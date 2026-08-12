<?php

declare(strict_types=1);

namespace Core;

class Response
{

    public static function json(array $data): void
    {

        header('Content-Type: application/json');

        echo json_encode($data);

        exit;

    }

    public static function text(string $text): void
    {

        echo $text;

        exit;

    }

}