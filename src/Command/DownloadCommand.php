<?php

declare(strict_types=1);

namespace App\Command;

use App\ConcurrentDownloader;
use App\DataObject\DownloadsResult;
use Psr\Log\LoggerInterface;
use React\EventLoop\Loop;
use React\Http\Browser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

#[AsCommand(name: 'app:download', description: 'Download all Aerones videos')]
class DownloadCommand extends Command
{
    const URLS = [
        'https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_30sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_40sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_50sec.mp4',
        'https://storage.googleapis.com/public_test_access_ae/output_60sec.mp4',
    ];

    public function __construct(
        protected LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/storage/')]
        protected                 $destinationDir,
        ?string                   $name = null,
    )
    {
        parent::__construct($name);
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output,
    ): int
    {
        $loop = Loop::get();
        $browser = new Browser(null, $loop);
        $downloader = new ConcurrentDownloader($this->logger);
        $tempDownloadBaseDir = sys_get_temp_dir() . '/aerones/';

        $output->writeln('Starting to download all Aerones videos...');

        $output->writeln('Temporarily downloading to: ' . $tempDownloadBaseDir);
        $output->writeln('Final destination: ' . $this->destinationDir);

        $this->ensureDirectoryExistence($tempDownloadBaseDir);
        $this->ensureDirectoryExistence($this->destinationDir);

        $downloadingMessageLoop = $loop->addPeriodicTimer(1, function () use ($output) {
            $output->writeln('Downloading..');
        });

        $downloader->download(
            $loop,
            $output,
            $browser,
            self::URLS,
            $tempDownloadBaseDir,
        )->then(
            function (DownloadsResult $result) use ($loop, $output, $downloadingMessageLoop) {
                $output->writeln('Finished processing all downloads!');

                $loop->cancelTimer($downloadingMessageLoop);

                $successfulDownloads = $result->getSuccessfulDownloads();
                $failedDownloads = $result->getFailedDownloads();

                $output->writeln("Downloaded " . count($successfulDownloads) . " file(s).");

                if (count($failedDownloads) > 0) {
                    $output->writeln("Failed to download " . count($failedDownloads) . " file(s).");
                }

                $this->moveFilePathsToDestinationDirectory(
                    array_map(
                        fn ($successfulDownloadPromise) => $successfulDownloadPromise->getValue()->getPath(),
                        $successfulDownloads
                    ),
                    $this->destinationDir
                );
            },
        );

        $loop->run();

        return Command::SUCCESS;
    }

    private function ensureDirectoryExistence(string $directoryPath): void
    {
        $filesystem = new Filesystem();

        $filesystem->mkdir(
            Path::normalize($directoryPath),
        );
    }

    /**
     * @param string[] $filePaths
     * @param string $destinationDir
     * @return void
     */
    private function moveFilePathsToDestinationDirectory(array $filePaths, string $destinationDir): void
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
