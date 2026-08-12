<?php

declare(strict_types=1);

namespace Core;

class Request
{

    public static function body(): string
    {

        return file_get_contents('php://input');

    }

    public static function json(): array
    {

        $json = json_decode(self::body(), true);

        return $json ?: [];

    }

}