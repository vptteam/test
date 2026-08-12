<?php

declare(strict_types=1);

namespace Services\Adapters\WhatsApp;

use Services\StorageService;
use Core\Logger;
use Throwable;

class WhatsAppMedia
{
    protected StorageService $storage;

    protected WhatsAppProviderInterface $provider;

    public function __construct()
    {
        Logger::write(
            'whatsapp_media_debug',
            [
                'step' => 'CONSTRUCTOR_START'
            ]
        );

        $this->storage = new StorageService();

        $this->provider = ProviderFactory::make();

        Logger::write(
            'whatsapp_media_debug',
            [
                'step' => 'CONSTRUCTOR_COMPLETE',
                'provider' => get_class($this->provider)
            ]
        );
    }

    /**
     * --------------------------------------------------------------------------
     * Download WhatsApp Image
     * --------------------------------------------------------------------------
     */
    public function image(
        array $message,
        string $folder = 'marketplace'
    ): ?array {

        try {

            Logger::write(
                'whatsapp_media_debug',
                [
                    'step' => 'IMAGE_START',
                    'provider_constant' =>
                        defined('WHATSAPP_PROVIDER')
                            ? WHATSAPP_PROVIDER
                            : null,
                    'message_keys' => array_keys($message),
                    'media' => $message['media'] ?? null
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Meta WhatsApp
            |--------------------------------------------------------------------------
            */

            if (
                defined('WHATSAPP_PROVIDER')
                &&
                WHATSAPP_PROVIDER === 'meta'
            ) {

                Logger::write(
                    'whatsapp_media_debug',
                    [
                        'step' => 'USING_META_PROVIDER'
                    ]
                );

                return $this->fromMeta(
                    $message,
                    $folder
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Twilio WhatsApp
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'whatsapp_media_debug',
                [
                    'step' => 'USING_TWILIO_PROVIDER'
                ]
            );

            return $this->fromTwilio(
                $message,
                $folder
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'whatsapp_media_error',
                [
                    'step' => 'IMAGE_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );

            return null;
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Meta WhatsApp
     * --------------------------------------------------------------------------
     */
    protected function fromMeta(
        array $message,
        string $folder
    ): ?array {

        try {

            $mediaId =
                $message['raw']['image']['id']
                ?? null;

            if (empty($mediaId)) {

                Logger::write(
                    'whatsapp_media_debug',
                    [
                        'step' => 'META_MEDIA_ID_MISSING'
                    ]
                );

                return null;
            }

            Logger::write(
                'whatsapp_media_debug',
                [
                    'step' => 'META_MEDIA_ID_FOUND',
                    'media_id' => $mediaId
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Get Meta media URL
            |--------------------------------------------------------------------------
            */

            $media = $this->provider->media(
                $mediaId
            );

            Logger::write(
                'whatsapp_media_debug',
                [
                    'step' => 'META_MEDIA_RESPONSE',
                    'response' => $media
                ]
            );

            if (
                empty($media)
                ||
                empty($media['url'])
            ) {

                Logger::write(
                    'whatsapp_media_error',
                    [
                        'step' => 'META_MEDIA_URL_MISSING',
                        'media_id' => $mediaId,
                        'response' => $media
                    ]
                );

                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Download Binary
            |--------------------------------------------------------------------------
            */

            $binary = $this->provider->download(
                $media['url']
            );

            if (empty($binary)) {

                Logger::write(
                    'whatsapp_media_error',
                    [
                        'step' => 'META_BINARY_EMPTY',
                        'url' => $media['url']
                    ]
                );

                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Save File
            |--------------------------------------------------------------------------
            */

            $extension =
                $this->extensionFromMime(
                    $media['mime_type'] ?? 'image/jpeg'
                );

            $filename =
                $this->storage->filename(
                    $extension
                );

            $path =
                $this->storage->save(
                    $folder,
                    $filename,
                    $binary
                );

            if (
                empty($path)
                ||
                !file_exists($path)
            ) {

                Logger::write(
                    'whatsapp_media_error',
                    [
                        'step' => 'META_SAVE_FAILED',
                        'filename' => $filename,
                        'path' => $path
                    ]
                );

                return null;
            }

            $fileSize =
                filesize($path);

            Logger::write(
                'whatsapp_media_debug',
                [
                    'step' => 'META_SAVE_COMPLETE',
                    'filename' => $filename,
                    'path' => $path,
                    'file_size' => $fileSize
                ]
            );

            return [

                'platform' => 'whatsapp',

                'media_type' => 'image',

                'filename' => $filename,

                'filepath' => $path,

                'mime_type' =>
                    $media['mime_type']
                    ?? 'image/jpeg',

                'file_size' =>
                    $fileSize ?: 0
            ];

        }

        catch (Throwable $e) {

            Logger::write(
                'whatsapp_media_error',
                [
                    'step' => 'META_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            );

            return null;
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Twilio WhatsApp
     * --------------------------------------------------------------------------
     *
     * IMPORTANT:
     *
     * WhatsAppListener creates:
     *
     * media = [
     *     'count' => 1,
     *     'items' => [
     *         [
     *             'url' => 'https://api.twilio.com/...',
     *             'content_type' => 'image/jpeg'
     *         ]
     *     ]
     * ]
     *
     * Therefore we must read:
     *
     * $message['media']['items'][0]['url']
     *
     */
    protected function fromTwilio(
        array $message,
        string $folder
    ): ?array {

        try {

            Logger::write(
                'whatsapp_media_debug',
                [
                    'step' => 'TWILIO_START',
                    'media' => $message['media'] ?? null
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Check Media Container
            |--------------------------------------------------------------------------
            */

            $media =
                $message['media']
                ?? null;

            if (
                empty($media)
                ||
                empty($media['items'])
                ||
                !is_array($media['items'])
            ) {

                Logger::write(
                    'whatsapp_media_debug',
                    [
                        'step' => 'TWILIO_MEDIA_ITEMS_MISSING'
                    ]
                );

                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Get First Media Item
            |--------------------------------------------------------------------------
            */

            $item =
                $media['items'][0]
                ?? null;

            if (
                empty($item)
                ||
                !is_array($item)
            ) {

                Logger::write(
                    'whatsapp_media_debug',
                    [
                        'step' => 'TWILIO_FIRST_MEDIA_ITEM_MISSING'
                    ]
                );

                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | Media URL
            |--------------------------------------------------------------------------
            */

            $mediaUrl =
                trim(
                    (string)(
                        $item['url']
                        ?? ''
                    )
                );

            if ($mediaUrl === '') {

                Logger::write(
                    'whatsapp_media_error',
                    [
                        'step' => 'TWILIO_MEDIA_URL_MISSING',
                        'item' => $item
                    ]
                );

                return null;
            }

            /*
            |--------------------------------------------------------------------------
            | MIME Type
            |--------------------------------------------------------------------------
            */

            $mimeType =
                trim(
                    (string)(
                        $item['content_type']
                        ?? 'image/jpeg'
                    )
                );

            Logger::write(
                'whatsapp_media_debug',
                [
                    'step' => 'TWILIO_MEDIA_FOUND',
                    'url' => $mediaUrl,
                    'mime_type' => $mimeType
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Download Binary From Twilio
            |--------------------------------------------------------------------------
            */

            $binary =
                $this->provider->download(
                    $mediaUrl
                );

            if (empty($binary)) {

                Logger::write(
                    'whatsapp_media_error',
                    [
                        'step' => 'TWILIO_BINARY_EMPTY',
                        'url' => $mediaUrl
                    ]
                );

                return null;
            }

            Logger::write(
                'whatsapp_media_debug',
                [
                    'step' => 'TWILIO_BINARY_DOWNLOADED',
                    'bytes' => strlen($binary)
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Determine File Extension
            |--------------------------------------------------------------------------
            */

            $extension =
                $this->extensionFromMime(
                    $mimeType
                );

            /*
            |--------------------------------------------------------------------------
            | Generate Filename
            |--------------------------------------------------------------------------
            */

            $filename =
                $this->storage->filename(
                    $extension
                );

            /*
            |--------------------------------------------------------------------------
            | Save File
            |--------------------------------------------------------------------------
            */

            $path =
                $this->storage->save(
                    $folder,
                    $filename,
                    $binary
                );

            if (
                empty($path)
                ||
                !file_exists($path)
            ) {

                Logger::write(
                    'whatsapp_media_error',
                    [
                        'step' => 'TWILIO_SAVE_FAILED',
                        'filename' => $filename,
                        'path' => $path
                    ]
                );

                return null;
            }

            $fileSize =
                filesize($path);

            Logger::write(
                'whatsapp_media_debug',
                [
                    'step' => 'TWILIO_SAVE_COMPLETE',
                    'filename' => $filename,
                    'path' => $path,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Return Media Data
            |--------------------------------------------------------------------------
            */

            return [

                'platform' => 'whatsapp',

                'media_type' => 'image',

                'filename' => $filename,

                'filepath' => $path,

                'mime_type' => $mimeType,

                'file_size' =>
                    $fileSize ?: 0
            ];

        }

        catch (Throwable $e) {

            Logger::write(
                'whatsapp_media_error',
                [
                    'step' => 'TWILIO_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            );

            return null;
        }
    }

    /**
     * --------------------------------------------------------------------------
     * MIME → Extension
     * --------------------------------------------------------------------------
     */
    protected function extensionFromMime(
        string $mime
    ): string {

        $mime = strtolower(
            trim($mime)
        );

        return match ($mime) {

            'image/jpeg',
            'image/jpg' => 'jpg',

            'image/png' => 'png',

            'image/webp' => 'webp',

            'image/gif' => 'gif',

            default => 'jpg'
        };
    }
}
