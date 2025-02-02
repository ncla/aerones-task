<?php

declare(strict_types=1);

namespace App\Command;

use Exception;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableResourceStream;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use React\Promise\Stream;
use function React\Promise\all;
use function React\Promise\reject;
use function React\Async\await;

#[AsCommand(name: 'app:download', description: 'Download all Aerones videos')]
class DownloadCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Starting to download all Aerones videos...');

        $urls = [
            'https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_30sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_40sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_50sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_60secs.mp4',
        ];

        $loop = Loop::get();
        $browser = new Browser(null, $loop);
        $browser = $browser->withTimeout(1);

        $promises = [];

        foreach ($urls as $url) {
            $promises[] = $this->settlePromise(
                $this->downloadFile(
                    $browser,
                    $loop,
                    $url,
                    basename($url)
                )
            );
        }

        $downloadingMessageLoop = $loop->addPeriodicTimer(1, function () use (&$promises, $output) {
            $output->writeln('Downloading...');
        });

        // If one promise rejects, the all()->then() will not call, thus we need equivalent to allSettled from JS,
        // where we resolve even if promise rejects. See settlePromise() wrapper method.
        all($promises)
            ->then(function () use ($promises, $output, $downloadingMessageLoop, $loop) {
                $successfulDownloads = array_filter($promises, function ($promise) {
                    return await($promise)['state'] === 'fulfilled';
                });

                $failedDownloads = array_filter($promises, function ($promise) {
                    return await($promise)['state'] === 'rejected';
                });

                $output->writeln("Downloaded " . count($successfulDownloads) . " file(s).");

                if (count($failedDownloads) > 0) {
                    $output->writeln("Failed to download " . count($failedDownloads) . " file(s).");
                }

                $loop->cancelTimer($downloadingMessageLoop);
            });

        $loop->run();

        return Command::SUCCESS;
    }

    protected function settlePromise(PromiseInterface $promise): PromiseInterface
    {
        return $promise->then(
            function ($value) {
                return ['state' => 'fulfilled', 'value' => $value];
            },
            function ($reason) {
                return ['state' => 'rejected', 'reason' => $reason];
            }
        );
    }

    protected function downloadFile(
        Browser $browser,
        LoopInterface $loop,
        string $url,
        string $saveTo
    ): PromiseInterface
    {
        $writeableStream = new WritableResourceStream(
            fopen($saveTo, 'w'),
            $loop
        );

        return new Promise(function ($resolve, $reject) use ($url, $browser, $writeableStream, $saveTo) {
            return Stream\unwrapReadable(
                $browser
                    ->requestStreaming('GET', $url)
                    ->then(function (ResponseInterface $response) {
                        return $response->getBody();
                    },
                    function (Exception $e) use ($reject) {
                        echo "Error: " . $e->getMessage() . "\n";
                        return $reject($e);
                    })
            )
            ->pipe($writeableStream)
            ->on('close', function () use ($resolve, $saveTo) {
                echo "Downloaded $saveTo\n";
                $resolve(true);
            })
            ->on('error', function (Exception $e) use ($reject) {
                echo "Error: " . $e->getMessage() . "\n";
                $reject($e);
            });
        });
    }
}
