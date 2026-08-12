<?php

declare(strict_types=1);

namespace Services\Telegram;

use Core\Logger;

use Services\Adapters\Telegram\TelegramApi;

use Throwable;

class TelegramAdminNotificationService
{

    protected TelegramApi $telegram;

    public function __construct()
    {

        $this->telegram = new TelegramApi();

    }

    /**
     * ---------------------------------------------------------
     * Send To Admin Chat
     * ---------------------------------------------------------
     */
    protected function sendToAdmin(

        string $message,

        array $buttons = []

    ): array
    {

        return $this->telegram->sendMessage(

            TELEGRAM_ADMIN_CHAT_ID,

            $message,

            $buttons

        );

    }

    /**
     * ---------------------------------------------------------
     * Send To Group
     * ---------------------------------------------------------
     */
    protected function sendToGroup(

        string $message,

        array $buttons = []

    ): array
    {

        if (

            empty(
                TELEGRAM_ESCROW_GROUP_ID
            )

        ) {

            return [

                'ok' => false

            ];

        }

        return $this->telegram->sendMessage(

            TELEGRAM_ESCROW_GROUP_ID,

            $message,

            $buttons

        );

    }

    /**
     * ---------------------------------------------------------
     * Send To Both
     * ---------------------------------------------------------
     */
    public function send(

        string $message,

        array $buttons = []

    ): bool
    {

        try {

            Logger::write(

                'telegram_admin_notification',

                [

                    'step' => 'SEND',

                    'message' => $message

                ]

            );

            $admin = $this->sendToAdmin(

                $message,

                $buttons

            );

            $group = $this->sendToGroup(

                $message,

                $buttons

            );

            Logger::write(

                'telegram_admin_notification',

                [

                    'admin' => $admin,

                    'group' => $group

                ]

            );

            return true;

        }

        catch (Throwable $e) {

            Logger::write(

                'telegram_admin_notification_error',

                [

                    'message' => $e->getMessage(),

                    'line' => $e->getLine(),

                    'file' => $e->getFile()

                ]

            );

            return false;

        }

    }

        /**
     * ---------------------------------------------------------
     * Notify Escrow Ready
     * ---------------------------------------------------------
     */
    public function notifyEscrowReady(

        array $escrow,

        array $wallet

    ): bool
    {

        $message =

            "🛡 *ESCROW READY FOR PAYOUT*\n\n"

            .

            "*Reference*\n"

            .

            ($escrow['reference'] ?? '-')

            .

            "\n\n"

            .

            "*Buyer*\n"

            .

            ($escrow['buyer_name'] ?? $escrow['buyer_id'] ?? '-')

            .

            "\n\n"

            .

            "*Seller*\n"

            .

            ($escrow['seller_name'] ?? $escrow['seller_id'] ?? '-')

            .

            "\n\n"

            .

            "*Amount*\n"

            .

            "₦"

            .

            number_format(

                (float)($escrow['seller_amount'] ?? 0),

                2

            )

            .

            "\n\n"

            .

            "*Bank*\n"

            .

            ($wallet['bank_name'] ?? '-')

            .

            "\n"

            .

            "*Account Name*\n"

            .

            ($wallet['account_name'] ?? '-')

            .

            "\n"

            .

            "*Account Number*\n"

            .

            ($wallet['account_number'] ?? '-')

            .

            "\n\n"

            .

            "Buyer has confirmed delivery.\n"

            .

            "Review before payment.";

        $buttons = [

            [

                [

                    'text' => '✅ Mark Paid',

                    'callback_data' =>

                        'paid:'

                        .

                        ($escrow['reference'] ?? '')

                ],

                [

                    'text' => '📄 Details',

                    'callback_data' =>

                        'details:'

                        .

                        ($escrow['reference'] ?? '')

                ]

            ]

        ];

        return $this->send(

            $message,

            $buttons

        );

    }

    /**
     * ---------------------------------------------------------
     * Notify Escrow Paid
     * ---------------------------------------------------------
     */
    public function notifyEscrowPaid(

        array $escrow,

        string $transferReference,

        string $adminName = 'Administrator'

    ): bool
    {

        $message =

            "✅ *ESCROW PAID*\n\n"

            .

            "*Reference*\n"

            .

            ($escrow['reference'] ?? '-')

            .

            "\n\n"

            .

            "*Amount*\n"

            .

            "₦"

            .

            number_format(

                (float)($escrow['seller_amount'] ?? 0),

                2

            )

            .

            "\n\n"

            .

            "*Transfer Reference*\n"

            .

            $transferReference

            .

            "\n\n"

            .

            "*Approved By*\n"

            .

            $adminName

            .

            "\n\n"

            .

            "Escrow has been completed successfully.";

        return $this->send(

            $message

        );

    }

       /**
     * ---------------------------------------------------------
     * Notify Withdrawal Request
     * ---------------------------------------------------------
     */
    public function notifyWithdrawalReady(

        array $withdrawal

    ): bool
    {

        $message =

            "💸 *WITHDRAWAL REQUEST*\n\n"

            .

            "*Reference*\n"

            .

            ($withdrawal['reference'] ?? '-')

            .

            "\n\n"

            .

            "*User*\n"

            .

            ($withdrawal['user_name']

            ??

            $withdrawal['user_id']

            ??

            '-')

            .

            "\n\n"

            .

            "*Amount*\n"

            .

            "₦"

            .

            number_format(

                (float)($withdrawal['amount'] ?? 0),

                2

            )

            .

            "\n\n"

            .

            "Please review this withdrawal.";

        $buttons = [

            [

                [

                    'text' => '✅ Approve',

                    'callback_data' =>

                        'withdrawal:approve:'

                        .

                        ($withdrawal['reference'] ?? '')

                ],

                [

                    'text' => '❌ Reject',

                    'callback_data' =>

                        'withdrawal:reject:'

                        .

                        ($withdrawal['reference'] ?? '')

                ]

            ]

        ];

        return $this->send(

            $message,

            $buttons

        );

    }

    /**
     * ---------------------------------------------------------
     * Marketplace Report
     * ---------------------------------------------------------
     */
    public function notifyMarketplaceReport(

        array $listing

    ): bool
    {

        $message =

            "🚨 *MARKETPLACE REPORT*\n\n"

            .

            "*Listing*\n"

            .

            ($listing['title'] ?? '-')

            .

            "\n\n"

            .

            "*Seller*\n"

            .

            ($listing['seller_name']

            ??

            $listing['seller_id']

            ??

            '-')

            .

            "\n\n"

            .

            "*Reason*\n"

            .

            ($listing['reason'] ?? '-');

        $buttons = [

            [

                [

                    'text' => '📄 View',

                    'callback_data' =>

                        'listing:view:'

                        .

                        ($listing['id'] ?? 0)

                ]

            ]

        ];

        return $this->send(

            $message,

            $buttons

        );

    }

    /**
     * ---------------------------------------------------------
     * System Alert
     * ---------------------------------------------------------
     */
    public function notifySystemAlert(

        string $title,

        string $message

    ): bool
    {

        return $this->send(

            "⚠️ *{$title}*\n\n{$message}"

        );

    }


}