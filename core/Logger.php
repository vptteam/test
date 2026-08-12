<?php

declare(strict_types=1);

namespace Core;

use Throwable;

class Logger
{
    /**
     * Write a log entry.
     *
     * Logging must NEVER break bot execution.
     */
    public static function write(
        string $file,
        mixed $data
    ): void {

        try {

            /*
             * ---------------------------------------------------------
             * Ensure log directory exists
             * ---------------------------------------------------------
             */

            if (!defined('LOG_PATH')) {
                return;
            }

            $logPath = rtrim((string) LOG_PATH, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR;

            if (!is_dir($logPath)) {

                if (!@mkdir($logPath, 0777, true) && !is_dir($logPath)) {
                    return;
                }

            }


            /*
             * ---------------------------------------------------------
             * Build log filename
             * ---------------------------------------------------------
             */

            $path = $logPath
                . basename($file)
                . '.log';


            /*
             * ---------------------------------------------------------
             * Timestamp
             * ---------------------------------------------------------
             */

            $log = '['
                . date('Y-m-d H:i:s')
                . '] ';


            /*
             * ---------------------------------------------------------
             * Convert data to compact string
             * ---------------------------------------------------------
             */

            if (is_array($data) || is_object($data)) {

                $encoded = json_encode(
                    $data,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_PARTIAL_OUTPUT_ON_ERROR
                );

                if ($encoded === false) {

                    $log .= '[JSON_ENCODE_FAILED]';

                } else {

                    $log .= $encoded;

                }

            } elseif (is_bool($data)) {

                $log .= $data ? 'true' : 'false';

            } elseif ($data === null) {

                $log .= 'null';

            } else {

                $log .= (string) $data;

            }


            /*
             * ---------------------------------------------------------
             * Write log
             *
             * @ suppresses filesystem warnings.
             * Logging must never interrupt the bot.
             * ---------------------------------------------------------
             */

            $log .= PHP_EOL;

            @file_put_contents(
                $path,
                $log,
                FILE_APPEND | LOCK_EX
            );

        } catch (Throwable $e) {

            /*
             * ---------------------------------------------------------
             * NEVER allow Logger to crash the bot.
             *
             * We intentionally do nothing here.
             * ---------------------------------------------------------
             */

            return;
        }
    }


    /**
     * Error log.
     */
    public static function error(
        string $file,
        mixed $data
    ): void {

        self::write(
            $file,
            [
                'level' => 'ERROR',
                'data'  => $data
            ]
        );
    }


    /**
     * Information log.
     */
    public static function info(
        string $file,
        mixed $data
    ): void {

        self::write(
            $file,
            [
                'level' => 'INFO',
                'data'  => $data
            ]
        );
    }


    /**
     * Warning log.
     */
    public static function warning(
        string $file,
        mixed $data
    ): void {

        self::write(
            $file,
            [
                'level' => 'WARNING',
                'data'  => $data
            ]
        );
    }


    /**
     * Debug log.
     */
    public static function debug(
        string $file,
        mixed $data
    ): void {

        self::write(
            $file,
            [
                'level' => 'DEBUG',
                'data'  => $data
            ]
        );
    }
}