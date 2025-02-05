<?php

declare(strict_types=1);

namespace App\Command;

use App\DataObject\DownloadedFile;
use App\ReactPHP\Retrier;
use App\ReactPHP\SettledFulfilledPromiseResult;
use App\ReactPHP\SettledPromiseResult;
use App\ReactPHP\SettledRejectedPromiseResult;
use Exception;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Http\Message\ResponseException;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Promise\Stream;
use React\Stream\WritableResourceStream;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use function React\Async\await;
use function React\Promise\all;
use function React\Promise\reject;
#[AsCommand(name: 'app:download', description: 'Download all Aerones videos')]
class DownloadCommand extends Command
{
    const URLS = [
        'https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_30sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_40sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_50sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_60secs.mp4',
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/storage/')]
        private $destinationDirectory,
        ?string $name = null,
    )
    {
        parent::__construct($name);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $output->writeln('Starting to download all Aerones videos...');

        $loop = Loop::get();
        $browser = new Browser(null, $loop);
        // TODO: only affects the establishing HTTP request connection? does not take transfer duration into account.
        $browser = $browser->withTimeout(1);

        $promises = [];

        $tempDownloadBaseDir = sys_get_temp_dir() . '/aerones/';

        $this->ensureDirectoryExistance($tempDownloadBaseDir);
        $this->ensureDirectoryExistance($this->destinationDirectory);

        foreach (self::URLS as $url) {
            $bytesDownloaded = $this->getExistingBytesDownloadedAmount($tempDownloadBaseDir . basename($url));

            $promises[] = $this->settlePromise(
                Retrier::attempt(
                    $loop,
                    3,
                    fn () => new Promise(function ($resolve, $reject) use ($bytesDownloaded, $tempDownloadBaseDir, $url, $loop, $browser) {
                        $this->downloadFile(
                            $browser,
                            $loop,
                            $url,
                            $tempDownloadBaseDir . basename($url),
                            $bytesDownloaded
                        )->then($resolve, $reject);
                    })
                )
            );
        }

        $downloadingMessageLoop = $loop->addPeriodicTimer(1, function () use (&$promises, $output) {
            $output->writeln('Downloading...');
        });

        $successfulDownloads = [];
        $failedDownloads = [];

        // If one promise rejects, the all()->then() will not call, thus we need equivalent to allSettled from JS,
        // where we resolve even if promise rejects. See settlePromise() wrapper method.
        all($promises)
            ->then(function () use ($promises, $output, $downloadingMessageLoop, $loop, &$successfulDownloads, &$failedDownloads) {
                $awaitedPromises = array_map(
                    function ($promise) {
                        return await($promise);
                    },
                    $promises
                );

                /** @var SettledPromiseResult[] $awaitedPromises */
                /** @var SettledFulfilledPromiseResult[] $successfulDownloads **/
                $successfulDownloads = array_filter($awaitedPromises,
                    function ($promise) {
                        return $promise->getState() === SettledPromiseResult::STATE_FULFILLED;
                    }
                );

                /** @var SettledRejectedPromiseResult[] $failedDownloads **/
                $failedDownloads = array_filter($awaitedPromises, function ($promise) {
                    return $promise->getState() === SettledPromiseResult::STATE_REJECTED;
                });

                $output->writeln("Downloaded " . count($successfulDownloads) . " file(s).");

                if (count($failedDownloads) > 0) {
                    $output->writeln("Failed to download " . count($failedDownloads) . " file(s).");
                }

                $loop->cancelTimer($downloadingMessageLoop);
            });

        $loop->run();

        $this->moveFilePathsToDestinationDirectory(
            array_map(
                fn ($successfulDownloadPromise) => $successfulDownloadPromise->getValue()->getPath(),
                $successfulDownloads
            ),
            $this->destinationDirectory
        );

        return Command::SUCCESS;
    }

    /**
     * @param PromiseInterface $promise
     * @return PromiseInterface<SettledFulfilledPromiseResult|SettledRejectedPromiseResult>
     */
    protected function settlePromise(PromiseInterface $promise): PromiseInterface
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

    protected function downloadFile(
        Browser       $browser,
        LoopInterface $loop,
        string        $downloadUrl,
        string        $saveTo,
        int           $bytesDownloaded = 0
    ): PromiseInterface
    {
        try {
            $filePointer = fopen(
                $saveTo,
                $bytesDownloaded === 0 ? 'w' : 'a'
            );
        } catch (Exception $e) {
            return reject($e);
        }

        $writeableStream = new WritableResourceStream(
            $filePointer,
            $loop
        );

        return new Promise(function ($resolve, $reject) use ($bytesDownloaded, $downloadUrl, $browser, $writeableStream, $saveTo) {
            return Stream\unwrapReadable(
                $browser
                    ->requestStreaming(
                        'GET',
                        $downloadUrl,
                        [
                            'Range' => "bytes=$bytesDownloaded-",
                        ]
                    )
                    ->then(function (ResponseInterface $response) {
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

                        echo "Error 1: " . $e->getMessage() . "\n";

                        return $reject($e);
                    })
            )
            ->pipe($writeableStream)
            ->on('close', function () use ($downloadUrl, $resolve, $saveTo) {
                echo "Downloaded $saveTo\n";

                return $resolve(
                    new DownloadedFile(
                        $downloadUrl,
                        basename($saveTo),
                        $saveTo
                    )
                );
            })
            ->on('error', function (Exception $e) use ($reject) {
                echo "Error 2: " . $e->getMessage() . "\n";
                $reject($e);
            });
        });
    }

    protected function getExistingBytesDownloadedAmount(string $filePath): int
    {
        $filesystem = new Filesystem();

        if ($filesystem->exists($filePath)) {
            return filesize($filePath);
        }

        return 0;
    }

    protected function ensureDirectoryExistance(string $directoryPath): void
    {
        $filesystem = new Filesystem();

        $filesystem->mkdir(
            Path::normalize($directoryPath),
        );
    }

    /**
     * @param string[] $filePaths
     * @return void
     */
    public function moveFilePathsToDestinationDirectory(array $filePaths, string $destinationDir): void
    {
        $filesystem = new Filesystem();

        foreach ($filePaths as $filePath) {
            $filesystem->rename(
                $filePath,
                $destinationDir . basename($filePath),
                true
            );
        }
    }
}
