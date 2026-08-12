<?php

declare(strict_types=1);

namespace Services\Telegram;

class TelegramAdminAuth
{

    /**
     * ---------------------------------------------------------
     * Is Telegram User Admin?
     * ---------------------------------------------------------
     */
    public function isAdmin(

        int|string $telegramId

    ): bool
    {

        $admins = $this->admins();

        return in_array(

            (string)$telegramId,

            $admins,

            true

        );

    }

    /**
     * ---------------------------------------------------------
     * Return Allowed Admin IDs
     * ---------------------------------------------------------
     */
    public function admins(): array
    {

        if (

            !defined('TELEGRAM_ALLOWED_ADMINS')

        ) {

            return [];

        }

        $list = trim(

            (string) TELEGRAM_ALLOWED_ADMINS

        );

        if ($list === '') {

            return [];

        }

        return array_filter(

            array_map(

                'trim',

                explode(',', $list)

            )

        );

    }

    /**
     * ---------------------------------------------------------
     * Throw Exception Helper
     * ---------------------------------------------------------
     */
    public function authorize(

        int|string $telegramId

    ): void
    {

        if (

            !$this->isAdmin(

                $telegramId

            )

        ) {

            throw new \RuntimeException(

                'Unauthorized Telegram administrator.'

            );

        }

    }

}