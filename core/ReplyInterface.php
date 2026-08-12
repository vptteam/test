<?php

declare(strict_types=1);

namespace Core;

interface ReplyInterface
{
    /**
     * Send text message.
     */
    public function text(
        string $recipient,
        string $message
    ): bool;

    /**
     * Send photo/image.
     */
    public function photo(
        string $recipient,
        string $photo,
        string $caption = ''
    ): bool;

    /**
     * Send document.
     */
    public function document(
        string $recipient,
        string $document,
        string $caption = ''
    ): bool;

    /**
     * Send video.
     */
    public function video(
        string $recipient,
        string $video,
        string $caption = ''
    ): bool;

    /**
     * Send interactive buttons.
     */
    public function buttons(
        string $recipient,
        string $message,
        array $buttons
    ): bool;

    /**
     * Send a numbered menu/list.
     */
    public function menu(
        string $recipient,
        string $title,
        array $items
    ): bool;

    /**
     * Show typing indicator.
     */
    public function typing(
        string $recipient
    ): bool;
}