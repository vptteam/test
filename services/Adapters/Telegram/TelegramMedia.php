<?php

declare(strict_types=1);

namespace Services\Adapters\Telegram;

use Core\Logger;
use Services\StorageService;
use Throwable;

class TelegramMedia
{
    protected TelegramApi $api;

    protected StorageService $storage;


    /**
     * ---------------------------------------------------------
     * CONSTRUCTOR
     * ---------------------------------------------------------
     */
    public function __construct()
    {
        Logger::write(
            'telegram_media_debug',
            [
                'step' => 'CONSTRUCTOR_START'
            ]
        );

        $this->api = new TelegramApi();

        Logger::write(
            'telegram_media_debug',
            [
                'step' => 'TELEGRAM_API_CREATED'
            ]
        );

        $this->storage = new StorageService();

        Logger::write(
            'telegram_media_debug',
            [
                'step' => 'STORAGE_SERVICE_CREATED'
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * DOWNLOAD TELEGRAM PHOTO
     * ---------------------------------------------------------
     *
     * 1. Ask Telegram for file information.
     * 2. Get Telegram file_path.
     * 3. Download the actual binary.
     * 4. Generate local filename.
     * 5. Save through StorageService.
     * 6. Verify saved file.
     * 7. Return normalized media array.
     *
     * ---------------------------------------------------------
     */
    public function photo(
        string $fileId,
        string $folder = 'marketplace'
    ): ?array {

        Logger::write(
            'telegram_media_debug',
            [
                'step' => 'PHOTO_METHOD_ENTERED',
                'file_id' => $fileId,
                'folder' => $folder
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Validate File ID
        |--------------------------------------------------------------------------
        */

        $fileId = trim($fileId);

        if ($fileId === '') {

            Logger::write(
                'telegram_media_error',
                [
                    'step' => 'FILE_ID_MISSING'
                ]
            );

            return null;
        }


        /*
        |--------------------------------------------------------------------------
        | Validate Folder
        |--------------------------------------------------------------------------
        */

        $folder = trim($folder);

        if ($folder === '') {
            $folder = 'marketplace';
        }


        try {

            /*
            |--------------------------------------------------------------------------
            | TELEGRAM getFile
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'GET_FILE_START',
                    'file_id' => $fileId
                ]
            );


            $result = $this->api->request(
                'getFile',
                [
                    'file_id' => $fileId
                ]
            );


            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'GET_FILE_RESPONSE',
                    'file_id' => $fileId,
                    'response' => $result
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate Telegram Response
            |--------------------------------------------------------------------------
            */

            if (!is_array($result)) {

                Logger::write(
                    'telegram_media_error',
                    [
                        'step' => 'GET_FILE_RESPONSE_NOT_ARRAY',
                        'file_id' => $fileId
                    ]
                );

                return null;
            }


            if (
                empty($result['ok'])
            ) {

                Logger::write(
                    'telegram_media_error',
                    [
                        'step' => 'GET_FILE_FAILED',
                        'file_id' => $fileId,
                        'response' => $result
                    ]
                );

                return null;
            }


            if (
                empty($result['result'])
                ||
                !is_array($result['result'])
            ) {

                Logger::write(
                    'telegram_media_error',
                    [
                        'step' => 'GET_FILE_RESULT_MISSING',
                        'file_id' => $fileId,
                        'response' => $result
                    ]
                );

                return null;
            }


            /*
            |--------------------------------------------------------------------------
            | Telegram File Path
            |--------------------------------------------------------------------------
            */

            $filePath = trim(
                (string)(
                    $result['result']['file_path']
                    ?? ''
                )
            );


            if ($filePath === '') {

                Logger::write(
                    'telegram_media_error',
                    [
                        'step' => 'FILE_PATH_MISSING',
                        'file_id' => $fileId,
                        'response' => $result
                    ]
                );

                return null;
            }


            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'FILE_PATH_RECEIVED',
                    'file_id' => $fileId,
                    'file_path' => $filePath
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate Telegram Bot Token
            |--------------------------------------------------------------------------
            */

            if (
                !defined('TELEGRAM_BOT_TOKEN')
                ||
                trim(
                    (string)TELEGRAM_BOT_TOKEN
                ) === ''
            ) {

                Logger::write(
                    'telegram_media_error',
                    [
                        'step' => 'BOT_TOKEN_MISSING',
                        'file_id' => $fileId
                    ]
                );

                return null;
            }


            /*
            |--------------------------------------------------------------------------
            | Build Telegram File URL
            |--------------------------------------------------------------------------
            */

            $url =
                'https://api.telegram.org/file/bot'
                .
                TELEGRAM_BOT_TOKEN
                .
                '/'
                .
                ltrim(
                    $filePath,
                    '/'
                );


            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'DOWNLOAD_URL_CREATED',
                    'file_id' => $fileId,

                    /*
                     * Do NOT log the bot token.
                     */
                    'file_path' => $filePath
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Download Binary
            |--------------------------------------------------------------------------
            |
            | file_get_contents() requires allow_url_fopen.
            |
            */

            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'BINARY_DOWNLOAD_START',
                    'file_id' => $fileId
                ]
            );


            $binary = @file_get_contents($url);


            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'BINARY_DOWNLOAD_RESULT',
                    'file_id' => $fileId,
                    'success' => $binary !== false,
                    'size' =>
                        $binary !== false
                            ? strlen($binary)
                            : 0,
                    'error' =>
                        $binary === false
                            ? error_get_last()
                            : null
                ]
            );


            if ($binary === false) {

                Logger::write(
                    'telegram_media_error',
                    [
                        'step' => 'BINARY_DOWNLOAD_FAILED',
                        'file_id' => $fileId,
                        'file_path' => $filePath,
                        'error' => error_get_last()
                    ]
                );

                return null;
            }


            /*
            |--------------------------------------------------------------------------
            | Validate Binary
            |--------------------------------------------------------------------------
            */

            if ($binary === '') {

                Logger::write(
                    'telegram_media_error',
                    [
                        'step' => 'EMPTY_BINARY',
                        'file_id' => $fileId,
                        'file_path' => $filePath
                    ]
                );

                return null;
            }


            $binarySize = strlen($binary);


            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'BINARY_VALID',
                    'file_id' => $fileId,
                    'size' => $binarySize
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Determine Extension
            |--------------------------------------------------------------------------
            */

            $extension = strtolower(
                pathinfo(
                    $filePath,
                    PATHINFO_EXTENSION
                )
            );


            if ($extension === '') {

                $extension = 'jpg';
            }


            /*
            |--------------------------------------------------------------------------
            | Normalize Extension
            |--------------------------------------------------------------------------
            */

            $extension =
                preg_replace(
                    '/[^a-z0-9]/i',
                    '',
                    $extension
                )
                ?: 'jpg';


            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'EXTENSION_DETECTED',
                    'file_id' => $fileId,
                    'extension' => $extension
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Generate Local Filename
            |--------------------------------------------------------------------------
            */

            $filename =
                $this->storage->filename(
                    $extension
                );


            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'FILENAME_GENERATED',
                    'file_id' => $fileId,
                    'filename' => $filename,
                    'folder' => $folder
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Save File
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'STORAGE_SAVE_START',
                    'file_id' => $fileId,
                    'folder' => $folder,
                    'filename' => $filename,
                    'binary_size' => $binarySize
                ]
            );


            $saved =
                $this->storage->save(
                    $folder,
                    $filename,
                    $binary
                );


            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'STORAGE_SAVE_RESULT',
                    'file_id' => $fileId,
                    'saved' => $saved
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate Saved Path
            |--------------------------------------------------------------------------
            */

            if (
                !is_string($saved)
                ||
                trim($saved) === ''
            ) {

                Logger::write(
                    'telegram_media_error',
                    [
                        'step' => 'STORAGE_RETURNED_INVALID_PATH',
                        'file_id' => $fileId,
                        'saved' => $saved
                    ]
                );

                return null;
            }


            /*
            |--------------------------------------------------------------------------
            | Verify Physical File
            |--------------------------------------------------------------------------
            */

            if (!is_file($saved)) {

                Logger::write(
                    'telegram_media_error',
                    [
                        'step' => 'SAVED_FILE_NOT_FOUND',
                        'file_id' => $fileId,
                        'saved' => $saved
                    ]
                );

                return null;
            }


            $savedSize =
                filesize($saved);


            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'SAVED_FILE_VERIFIED',
                    'file_id' => $fileId,
                    'saved' => $saved,
                    'size' => $savedSize
                ]
            );


            if (
                $savedSize === false
                ||
                $savedSize <= 0
            ) {

                Logger::write(
                    'telegram_media_error',
                    [
                        'step' => 'SAVED_FILE_EMPTY',
                        'file_id' => $fileId,
                        'saved' => $saved,
                        'size' => $savedSize
                    ]
                );

                return null;
            }


            /*
            |--------------------------------------------------------------------------
            | MIME TYPE
            |--------------------------------------------------------------------------
            */

            $mimeType = 'image/jpeg';


            if (
                function_exists('mime_content_type')
            ) {

                $detectedMime =
                    @mime_content_type(
                        $saved
                    );


                if (
                    is_string($detectedMime)
                    &&
                    str_starts_with(
                        $detectedMime,
                        'image/'
                    )
                ) {

                    $mimeType = $detectedMime;
                }
            }


            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'MIME_DETECTED',
                    'file_id' => $fileId,
                    'mime_type' => $mimeType
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Final Media Record
            |--------------------------------------------------------------------------
            */

            $media = [

                'platform' =>
                    'telegram',

                'media_type' =>
                    'image',

                'media_id' =>
                    $fileId,

                'filename' =>
                    $filename,

                'filepath' =>
                    $saved,

                'mime_type' =>
                    $mimeType,

                'file_size' =>
                    $savedSize,

                'width' =>
                    0,

                'height' =>
                    0

            ];


            Logger::write(
                'telegram_media_debug',
                [
                    'step' => 'PHOTO_COMPLETE',
                    'file_id' => $fileId,
                    'media' => $media
                ]
            );


            return $media;

        }
        catch (Throwable $e) {

            Logger::write(
                'telegram_media_error',
                [
                    'step' => 'PHOTO_EXCEPTION',
                    'file_id' => $fileId,
                    'folder' => $folder,
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );

            return null;
        }
    }
}
