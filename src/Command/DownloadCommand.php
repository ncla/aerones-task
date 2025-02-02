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

#[AsCommand(name: 'app:download', description: 'Download all Aerones videos')]
class DownloadCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Downloading all Aerones videos...');

        $urls = [
            'https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_30sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_40sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_50sec.mp4',
            'https://storage.googleapis.com/public_test_access_ae/output_60sec.mp4',
        ];

        $loop = Loop::get();
        $browser = new Browser(null, $loop);
        $browser = $browser->withTimeout(1);

        $promises = [];

        foreach ($urls as $url) {
            $promises[] = $this->downloadFile(
                $browser,
                $loop,
                $url,
                basename($url)
            );
        }

        $downloadingProgressMessageLoop = $loop->addPeriodicTimer(1, function () use (&$promises, $output) {
            $output->writeln('Downloading...');
        });

        all($promises)->then(function () use ($output, $downloadingProgressMessageLoop, $loop) {
            $output->writeln('All videos downloaded successfully!');
            $loop->cancelTimer($downloadingProgressMessageLoop);
        });

        $loop->run();

        return Command::SUCCESS;
    }

    protected function downloadFile(Browser $browser, LoopInterface $loop, string $url, string $saveTo)
    {
        $writeableStream = new WritableResourceStream(
            fopen($saveTo, 'w'),
            $loop
        );

        $stream = Stream\unwrapReadable(
            $browser
                ->requestStreaming('GET', $url)
                ->then(function (ResponseInterface $response) {
                    return $response->getBody();
                })
        );

        return new Promise(function ($resolve, $reject) use ($stream, $writeableStream, $saveTo) {
            $stream
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
