<?php

namespace App\DataObject;

class DownloadsResult
{
    public function __construct(
        private array $successfulDownloads,
        private array $failedDownloads
    ) {}

    public function getSuccessfulDownloads(): array
    {
        return $this->successfulDownloads;
    }

    public function getFailedDownloads(): array
    {
        return $this->failedDownloads;
    }
}
