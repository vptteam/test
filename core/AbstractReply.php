<?php

declare(strict_types=1);

namespace Core;

abstract class AbstractReply implements ReplyInterface
{
    public function typing(string $recipient): bool
    {
        return true;
    }

    public function video(
        string $recipient,
        string $video,
        string $caption = ''
    ): bool
    {
        return false;
    }

    public function document(
        string $recipient,
        string $document,
        string $caption = ''
    ): bool
    {
        return false;
    }
}