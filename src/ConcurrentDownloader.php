<?php

namespace App;

use App\DataObject\DownloadedFile;
use App\DataObject\DownloadsResult;
use App\ReactPHP\Retrier;
use App\ReactPHP\SettledFulfilledPromiseResult;
use App\ReactPHP\SettledPromiseResult;
use App\ReactPHP\SettledRejectedPromiseResult;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Stream\WritableResourceStream;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use function React\Async\await;
use function React\Promise\all;
use function React\Promise\reject;
use function React\Promise\Stream\unwrapReadable;

class ConcurrentDownloader
{
    public function __construct(
        protected LoggerInterface $logger
    )
    {
    }

    public function download(
        LoopInterface   $loop,
        OutputInterface $output,
        Browser         $browser,
        array           $downloadUrls,
        string          $targetDir,
    ): PromiseInterface
    {
        $promises = [];

        foreach ($downloadUrls as $url) {
            $bytesDownloaded = $this->getExistingBytesDownloadedAmount($targetDir . basename($url));

            $promises[] = $this->settlePromise(
                Retrier::attempt(
                    $loop,
                    $output,
                    3,
                    fn() => new Promise(function ($resolve, $reject) use ($output, $bytesDownloaded, $targetDir, $url, $loop, $browser) {
                        $this->downloadFile(
                            $browser,
                            $loop,
                            $output,
                            $url,
                            $targetDir . basename($url),
                            $bytesDownloaded
                        )->then($resolve, $reject);
                    })
                )
            );
        }

        // If one promise rejects, the all()->then() will not call, thus we need equivalent to allSettled from JS,
        // where we resolve even if promise rejects. See settlePromise() wrapper method.
        return all($promises)
            ->then(function () use ($promises, $output, $loop, &$successfulDownloads, &$failedDownloads) {
                $awaitedPromises = array_map(
                    function ($promise) {
                        return await($promise);
                    },
                    $promises
                );

                /** @var SettledPromiseResult[] $awaitedPromises */
                /** @var SettledFulfilledPromiseResult[] $successfulDownloads * */
                $successfulDownloads = array_filter($awaitedPromises,
                    function ($promise) {
                        return $promise->getState() === SettledPromiseResult::STATE_FULFILLED;
                    }
                );

                /** @var SettledRejectedPromiseResult[] $failedDownloads * */
                $failedDownloads = array_filter($awaitedPromises, function ($promise) {
                    return $promise->getState() === SettledPromiseResult::STATE_REJECTED;
                });

                return new DownloadsResult(
                    $successfulDownloads,
                    $failedDownloads
                );
            });
    }

    private function downloadFile(
        Browser         $browser,
        LoopInterface   $loop,
        OutputInterface $output,
        string          $downloadUrl,
        string          $saveTo,
        int             $bytesDownloaded = 0
    ): PromiseInterface
    {
        try {
            $filePointer = fopen(
                $saveTo,
                $bytesDownloaded === 0 ? 'w' : 'a'
            );
        } catch (Exception $e) {
            $this->logger->error($e);
            return reject($e);
        }

        $writeableStream = new WritableResourceStream(
            $filePointer,
            $loop
        );

        return new Promise(function ($resolve, $reject) use ($output, $bytesDownloaded, $downloadUrl, $browser, $writeableStream, $saveTo) {
            return unwrapReadable(
                $browser
                    ->requestStreaming(
                        'GET',
                        $downloadUrl,
                        [
                            'Range' => "bytes=$bytesDownloaded-",
                        ]
                    )
                    ->then(
                        function (ResponseInterface $response) use ($downloadUrl, $output) {
                            $output->writeln("URL: " . $downloadUrl . " - HTTP Status code: " . $response->getStatusCode());
                            return $response->getBody();
                        },
                        function (Exception $e) use ($downloadUrl, $saveTo, $bytesDownloaded, $resolve, $reject) {
                            // Most likely means we already have the full file downloaded
                            if (
                                $e instanceof ResponseException &&
                                $e->getCode() === 416 &&
                                $bytesDownloaded > 0
                            ) {
                                return $resolve(
                                    new DownloadedFile(
                                        $downloadUrl,
                                        basename($saveTo),
                                        $saveTo
                                    )
                                );
                            }

                            $this->logger->error($e);

                            return $reject($e);
                        }
                    )
            )
            ->pipe($writeableStream)
            ->on('close', function () use ($output, $downloadUrl, $resolve, $saveTo) {
                $output->writeln("Downloaded: " . basename($downloadUrl));

                return $resolve(
                    new DownloadedFile(
                        $downloadUrl,
                        basename($saveTo),
                        $saveTo
                    )
                );
            })
            ->on('error', function (Exception $e) use ($reject) {
                $this->logger->error($e);
                $reject($e);
            });
        });
    }

    private function getExistingBytesDownloadedAmount(string $filePath): int
    {
        $filesystem = new Filesystem();

        if ($filesystem->exists($filePath)) {
            return filesize($filePath);
        }

        return 0;
    }

    /**
     * @param PromiseInterface $promise
     * @return PromiseInterface<SettledFulfilledPromiseResult|SettledRejectedPromiseResult>
     */
    private function settlePromise(PromiseInterface $promise): PromiseInterface
    {
        return $promise->then(
            function ($value) {
                return new SettledFulfilledPromiseResult($value);
            },
            function ($reason) {
                return new SettledRejectedPromiseResult($reason);
            }
        );
    }
}
