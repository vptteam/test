<?php

declare(strict_types=1);

namespace Services\Publishers;

interface PublisherInterface
{
    public function publish(
        array $listing
    ): bool;
}