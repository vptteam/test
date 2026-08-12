<?php

declare(strict_types=1);

namespace Services;

use Models\Media;

class MediaService
{
    protected StorageService $storage;

    protected Media $media;

    public function __construct()
    {
        $this->storage = new StorageService();

        $this->media = new Media();
    }

    /**
     * Register Media
     */
    public function register(array $data): int
    {
        return $this->media->create($data);
    }
}