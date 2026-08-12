<?php

declare(strict_types=1);

namespace Services;

use Core\Logger;
use RuntimeException;
use Throwable;

class StorageService
{
    /**
     * ---------------------------------------------------------
     * Get / Create Storage Directory
     * ---------------------------------------------------------
     */
    public function directory(string $folder): string
    {
        try {

            Logger::write(
                'storage_service',
                [
                    'step'   => 'DIRECTORY_START',
                    'folder' => $folder
                ]
            );

            if (!defined('UPLOAD_PATH')) {

                Logger::write(
                    'storage_service_error',
                    [
                        'step'    => 'UPLOAD_PATH_NOT_DEFINED',
                        'folder'  => $folder
                    ]
                );

                throw new RuntimeException(
                    'UPLOAD_PATH is not defined.'
                );
            }

            $basePath = (string)UPLOAD_PATH;

            if (trim($basePath) === '') {

                Logger::write(
                    'storage_service_error',
                    [
                        'step' => 'UPLOAD_PATH_EMPTY'
                    ]
                );

                throw new RuntimeException(
                    'UPLOAD_PATH is empty.'
                );
            }

            /*
             * Make sure the base path ends with /
             */
            $basePath =
                rtrim(
                    $basePath,
                    DIRECTORY_SEPARATOR
                )
                .
                DIRECTORY_SEPARATOR;

            /*
             * Prevent accidental path traversal.
             */
            $folder =
                trim(
                    $folder,
                    "/\\"
                );

            if (
                $folder === ''
                ||
                $folder === '.'
                ||
                $folder === '..'
                ||
                str_contains($folder, '..')
            ) {

                Logger::write(
                    'storage_service_error',
                    [
                        'step'   => 'INVALID_FOLDER',
                        'folder' => $folder
                    ]
                );

                throw new RuntimeException(
                    'Invalid storage folder.'
                );
            }

            $path =
                $basePath
                .
                $folder
                .
                DIRECTORY_SEPARATOR;

            /*
             * Create directory when missing.
             */
            if (!is_dir($path)) {

                Logger::write(
                    'storage_service',
                    [
                        'step' => 'DIRECTORY_CREATING',
                        'path' => $path
                    ]
                );

                if (
                    !mkdir(
                        $path,
                        0775,
                        true
                    )
                    &&
                    !is_dir($path)
                ) {

                    Logger::write(
                        'storage_service_error',
                        [
                            'step' => 'DIRECTORY_CREATE_FAILED',
                            'path' => $path,
                            'error' => error_get_last()
                        ]
                    );

                    throw new RuntimeException(
                        'Unable to create storage directory.'
                    );
                }
            }

            /*
             * Directory must exist.
             */
            if (!is_dir($path)) {

                throw new RuntimeException(
                    'Storage directory does not exist.'
                );
            }

            /*
             * Directory must be writable.
             */
            if (!is_writable($path)) {

                Logger::write(
                    'storage_service_error',
                    [
                        'step' => 'DIRECTORY_NOT_WRITABLE',
                        'path' => $path,
                        'permissions' =>
                            substr(
                                sprintf(
                                    '%o',
                                    fileperms($path)
                                ),
                                -4
                            )
                    ]
                );

                throw new RuntimeException(
                    'Storage directory is not writable.'
                );
            }

            Logger::write(
                'storage_service',
                [
                    'step' => 'DIRECTORY_READY',
                    'path' => $path
                ]
            );

            return $path;

        }
        catch (Throwable $e) {

            Logger::write(
                'storage_service_error',
                [
                    'step'    => 'DIRECTORY_EXCEPTION',
                    'folder'  => $folder,
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine()
                ]
            );

            throw $e;
        }
    }


    /**
     * ---------------------------------------------------------
     * Generate Filename
     * ---------------------------------------------------------
     */
    public function filename(string $extension): string
    {
        $extension =
            strtolower(
                trim(
                    $extension
                )
            );

        if ($extension === '') {
            $extension = 'jpg';
        }

        /*
         * Remove unsafe characters.
         */
        $extension =
            preg_replace(
                '/[^a-z0-9]/i',
                '',
                $extension
            )
            ?: 'jpg';

        $filename =
            uniqid(
                '',
                true
            )
            .
            '_'
            .
            time()
            .
            '.'
            .
            $extension;

        Logger::write(
            'storage_service',
            [
                'step'      => 'FILENAME_GENERATED',
                'extension' => $extension,
                'filename'  => $filename
            ]
        );

        return $filename;
    }


    /**
     * ---------------------------------------------------------
     * Save Binary File
     * ---------------------------------------------------------
     */
    public function save(
        string $folder,
        string $filename,
        string $binary
    ): string {

        try {

            Logger::write(
                'storage_service',
                [
                    'step'     => 'SAVE_START',
                    'folder'   => $folder,
                    'filename' => $filename,
                    'size'     => strlen($binary)
                ]
            );

            if ($binary === '') {

                Logger::write(
                    'storage_service_error',
                    [
                        'step' => 'EMPTY_BINARY',
                        'folder' => $folder,
                        'filename' => $filename
                    ]
                );

                throw new RuntimeException(
                    'Cannot save empty file.'
                );
            }

            /*
             * Validate filename.
             */
            $filename =
                basename(
                    $filename
                );

            if (
                $filename === ''
                ||
                $filename === '.'
                ||
                $filename === '..'
            ) {

                throw new RuntimeException(
                    'Invalid filename.'
                );
            }

            $directory =
                $this->directory(
                    $folder
                );

            $path =
                $directory
                .
                $filename;

            Logger::write(
                'storage_service',
                [
                    'step' => 'FILE_WRITE_START',
                    'path' => $path
                ]
            );

            $bytes =
                file_put_contents(
                    $path,
                    $binary,
                    LOCK_EX
                );

            /*
             * file_put_contents() returns false on failure.
             */
            if ($bytes === false) {

                Logger::write(
                    'storage_service_error',
                    [
                        'step'  => 'FILE_WRITE_FAILED',
                        'path'  => $path,
                        'error' => error_get_last()
                    ]
                );

                throw new RuntimeException(
                    'Unable to write media file.'
                );
            }

            /*
             * Verify file physically exists.
             */
            if (!is_file($path)) {

                Logger::write(
                    'storage_service_error',
                    [
                        'step' => 'FILE_NOT_FOUND_AFTER_WRITE',
                        'path' => $path
                    ]
                );

                throw new RuntimeException(
                    'File was not created after write.'
                );
            }

            $actualSize =
                filesize(
                    $path
                );

            if (
                $actualSize === false
                ||
                $actualSize <= 0
            ) {

                Logger::write(
                    'storage_service_error',
                    [
                        'step' => 'INVALID_SAVED_FILE_SIZE',
                        'path' => $path,
                        'size' => $actualSize
                    ]
                );

                throw new RuntimeException(
                    'Saved file is empty or unreadable.'
                );
            }

            Logger::write(
                'storage_service',
                [
                    'step'          => 'FILE_SAVED',
                    'path'          => $path,
                    'bytes_written' => $bytes,
                    'file_size'     => $actualSize
                ]
            );

            return $path;

        }
        catch (Throwable $e) {

            Logger::write(
                'storage_service_error',
                [
                    'step'     => 'SAVE_EXCEPTION',
                    'folder'   => $folder,
                    'filename' => $filename,
                    'message'  => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                    'trace'    => $e->getTraceAsString()
                ]
            );

            throw $e;
        }
    }
}
