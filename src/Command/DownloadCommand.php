<?php

declare(strict_types=1);

namespace App\Command;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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

        $this->downloadFile(
            'https://storage.googleapis.com/public_test_access_ae/output_20sec.mp4',
            'output_20sec.mp4'
        );

        $output->writeln('All videos downloaded successfully!');

        return Command::SUCCESS;
    }

    /**
     * @throws Exception
     */
    protected function downloadFile(string $url, string $saveTo, $debug = false)
    {
        $existingSize = file_exists($saveTo) ? filesize($saveTo) : 0;

        $curlHandle = curl_init();

        curl_setopt($curlHandle, CURLOPT_URL, $url);

        // Add Range option only if there's an existing partial file.
        // TODO: Add a check to see if the server supports range requests. https://curl.se/libcurl/c/CURLOPT_RANGE.html
        if ($existingSize > 0) {
            curl_setopt($curlHandle, CURLOPT_RANGE, "{$existingSize}-");
        }

        $responseHeaders = [];

        if ($debug) {
            curl_setopt($curlHandle, CURLOPT_HEADERFUNCTION,
                function ($curl, $header) use (&$responseHeaders) {
                    $len = strlen($header);
                    $header = explode(':', $header, 2);
                    if (count($header) < 2) // ignore invalid headers
                        return $len;

                    $responseHeaders[strtolower(trim($header[0]))][] = trim($header[1]);

                    return $len;
                }
            );
        }

        curl_setopt($curlHandle, CURLOPT_FILE, fopen($saveTo, 'a')); // open in append mode
        curl_setopt($curlHandle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 3);

        if ($debug) {
            curl_setopt($curlHandle, CURLOPT_VERBOSE, true);
        }

        curl_exec($curlHandle);

        if ($debug) {
            print_r($responseHeaders);
        }

        if (curl_errno($curlHandle)) {
            throw new Exception('cURL error: ' . curl_error($curlHandle));
        }

        curl_close($curlHandle);
    }
}
