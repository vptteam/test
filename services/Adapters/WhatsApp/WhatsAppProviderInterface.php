<?php

declare(strict_types=1);

namespace Services\Adapters\WhatsApp;

interface WhatsAppProviderInterface
{
    public function text(
        string $recipient,
        string $message
    ): bool;

    public function image(
        string $recipient,
        string $image,
        string $caption=''
    ): bool;

    public function document(
        string $recipient,
        string $document,
        string $caption=''
    ): bool;

    public function menu(
        string $recipient,
        string $title,
        array $items
    ): bool;

    public function buttons(
        string $recipient,
        string $message,
        array $buttons
    ): bool;

    public function media(
        string $mediaId
    ): ?array;

    public function download(
        string $url
    ): ?string;
}