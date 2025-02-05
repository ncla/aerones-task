<?php

namespace App\DataObject;

class DownloadedFile
{
    public function __construct(
        private string $url,
        private string $filename,
        private string $path
    ) {}

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }
}
