<?php

declare(strict_types=1);

namespace Controllers;

use Core\Logger;
use Core\ReplyFactory;
use Models\AdvertUpgradePayment;
use Models\BotNotification;
use Models\PaymentNotification;
use Models\SellerPackage;
use Models\SellerSubscription;
use Services\Payments\PaystackVerifier;
use Throwable;

class AdvertPaymentController
{
    /**
     * ---------------------------------------------------------
     * Browser Callback
     * ---------------------------------------------------------
     *
     * Paystack redirects the customer here after payment.
     *
     * The callback uses the reference only and performs the
     * authoritative Paystack verification inside activatePayment().
     *
     * ---------------------------------------------------------
     */
    public function callback(): void
    {
        try {

            $reference = trim(
                (string)(
                    $_GET['reference']
                    ?? ''
                )
            );

            Logger::write(
                'advert_callback',
                [
                    'step'      => 'CALLBACK_RECEIVED',
                    'reference' => $reference,
                    'query'     => $_GET
                ]
            );

            if ($reference === '') {

                http_response_code(400);

                echo 'Missing payment reference.';

                return;
            }

            $activated =
                $this->activatePayment(
                    $reference
                );

            if (!$activated) {

                http_response_code(500);

                echo 'Unable to activate payment.';

                return;
            }

            ?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<title>Payment Successful</title>

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<style>

body {
    margin: 0;
    padding: 40px;
    font-family: Arial, sans-serif;
    background: #f8fafc;
    color: #111827;
    text-align: center;
}

.card {
    max-width: 700px;
    margin: auto;
    background: #ffffff;
    border-radius: 14px;
    padding: 45px;
    box-shadow: 0 10px 35px rgba(0,0,0,.08);
}

h1 {
    color: #16a34a;
}

.button {
    display: inline-block;
    margin-top: 30px;
    padding: 14px 28px;
    background: #0088cc;
    color: #fff;
    text-decoration: none;
    border-radius: 8px;
    font-weight: bold;
}

</style>

</head>

<body>

<div class="card">

    <h1>Payment Successful</h1>

    <p>
        Your payment has been confirmed successfully.
    </p>

    <p>
        Return to Telegram to continue your transaction.
    </p>

    <a
        href="https://t.me/sendambot"
        class="button"
    >
        Open SENDAM Bot
    </a>

</div>

</body>
</html>
<?php

        }
        catch (Throwable $e) {

            Logger::write(
                'advert_callback_error',
                [
                    'step'    => 'CALLBACK_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString()
                ]
            );

            http_response_code(500);

            echo 'Payment processing failed.';
        }
    }


    /**
 * --------------------------------------------------------------------------
 * Paystack Webhook
 * --------------------------------------------------------------------------
 *
 * The canonical Paystack webhook listener is responsible for:
 *
 * 1. Reading php://input
 * 2. Validating the Paystack signature
 * 3. Decoding JSON
 * 4. Validating the Paystack webhook envelope
 * 5. Routing escrow payments away from advert processing
 *
 * This controller receives the already-decoded payload.
 *
 * IMPORTANT:
 *
 * Do NOT read php://input here.
 * Do NOT validate the Paystack signature here.
 *
 * --------------------------------------------------------------------------
 */
public function webhook(array $payload = []): void
{
    try {

        Logger::write(
            'advert_webhook',
            [
                'step' =>
                    'CONTROLLER_WEBHOOK_RECEIVED',

                'payload_type' =>
                    gettype($payload),

                'event' =>
                    $payload['event']
                    ?? null,

                'reference' =>
                    $payload['data']['reference']
                    ?? null
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Validate Payload
        |--------------------------------------------------------------------------
        */

        if (
            !is_array($payload)
            ||
            empty($payload)
        ) {

            Logger::write(
                'advert_webhook_error',
                [
                    'step' =>
                        'INVALID_PAYLOAD'
                ]
            );

            /*
             * This should normally never happen because the listener
             * validates the payload before reaching this controller.
             */

            http_response_code(400);

            echo 'Invalid payload';

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Validate Event
        |--------------------------------------------------------------------------
        */

        $event =
            strtolower(
                trim(
                    (string)(
                        $payload['event']
                        ?? ''
                    )
                )
            );


        if (
            $event === ''
        ) {

            Logger::write(
                'advert_webhook_error',
                [
                    'step' =>
                        'EVENT_MISSING'
                ]
            );

            http_response_code(400);

            echo 'Invalid payload';

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Ignore Non-success Events
        |--------------------------------------------------------------------------
        */

        if (
            $event !== 'charge.success'
        ) {

            Logger::write(
                'advert_webhook',
                [
                    'step' =>
                        'EVENT_IGNORED',

                    'event' =>
                        $event
                ]
            );

            http_response_code(200);

            echo 'Ignored';

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Transaction Data
        |--------------------------------------------------------------------------
        */

        $transaction =
            $payload['data']
            ??
            null;


        if (
            !is_array($transaction)
        ) {

            Logger::write(
                'advert_webhook_error',
                [
                    'step' =>
                        'TRANSACTION_DATA_MISSING',

                    'event' =>
                        $event
                ]
            );

            http_response_code(400);

            echo 'Invalid payload';

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Reference
        |--------------------------------------------------------------------------
        */

        $reference =
            trim(
                (string)(
                    $transaction['reference']
                    ??
                    ''
                )
            );


        if (
            $reference === ''
        ) {

            Logger::write(
                'advert_webhook_error',
                [
                    'step' =>
                        'REFERENCE_MISSING',

                    'event' =>
                        $event
                ]
            );

            /*
             * The webhook was valid, but there is no usable payment
             * reference to process.
             */

            http_response_code(200);

            echo 'Missing reference';

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Metadata
        |--------------------------------------------------------------------------
        */

        $metadata =
            $transaction['metadata']
            ??
            [];


        if (
            !is_array($metadata)
        ) {

            $metadata = [];
        }


        /*
        |--------------------------------------------------------------------------
        | Payment Type
        |--------------------------------------------------------------------------
        */

        $paymentType =
            strtolower(
                trim(
                    (string)(
                        $metadata['type']
                        ??
                        ''
                    )
                )
            );


        Logger::write(
            'advert_webhook',
            [
                'step' =>
                    'PAYMENT_IDENTIFIED',

                'event' =>
                    $event,

                'reference' =>
                    $reference,

                'payment_type' =>
                    $paymentType,

                'metadata' =>
                    $metadata
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | ESCROW SAFETY CHECK
        |--------------------------------------------------------------------------
        |
        | Escrow payments must NEVER be activated through the advert
        | payment controller.
        |
        | The canonical escrow route is:
        |
        | /payment/paystack/escrow/webhook
        |
        */

        if (
            $paymentType === 'escrow'
        ) {

            Logger::write(
                'advert_webhook',
                [
                    'step' =>
                        'ESCROW_PAYMENT_REJECTED_FROM_ADVERT_CONTROLLER',

                    'reference' =>
                        $reference,

                    'escrow_id' =>
                        $metadata['escrow_id']
                        ?? null
                ]
            );


            /*
             * Do not call activatePayment().
             *
             * The dedicated escrow webhook must handle this payment.
             */

            http_response_code(200);

            echo 'OK';

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Validate Payment Status
        |--------------------------------------------------------------------------
        |
        | Paystack's webhook event is charge.success, but we still make
        | sure the transaction itself reports success before activation.
        |
        */

        $transactionStatus =
            strtolower(
                trim(
                    (string)(
                        $transaction['status']
                        ??
                        ''
                    )
                )
            );


        if (
            $transactionStatus !== ''
            &&
            $transactionStatus !== 'success'
        ) {

            Logger::write(
                'advert_webhook',
                [
                    'step' =>
                        'TRANSACTION_NOT_SUCCESSFUL',

                    'reference' =>
                        $reference,

                    'status' =>
                        $transactionStatus
                ]
            );

            http_response_code(200);

            echo 'Ignored';

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Activate Advert Payment
        |--------------------------------------------------------------------------
        */

        Logger::write(
            'advert_webhook',
            [
                'step' =>
                    'ACTIVATION_START',

                'reference' =>
                    $reference
            ]
        );


        $result =
            $this->activatePayment(
                $reference
            );


        Logger::write(
            'advert_webhook',
            [
                'step' =>
                    'ACTIVATION_RESULT',

                'reference' =>
                    $reference,

                'result' =>
                    $result
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Activation Failed
        |--------------------------------------------------------------------------
        */

        if (
            !$result
        ) {

            Logger::write(
                'advert_webhook_error',
                [
                    'step' =>
                        'ACTIVATION_FAILED',

                    'reference' =>
                        $reference
                ]
            );

            /*
             * We deliberately acknowledge the webhook.
             *
             * The payment has already been received by Paystack.
             * Repeated webhook retries should not create duplicate
             * subscription/payment processing.
             *
             * The detailed failure is already in the logs.
             */

            http_response_code(200);

            echo 'OK';

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Complete
        |--------------------------------------------------------------------------
        */

        Logger::write(
            'advert_webhook',
            [
                'step' =>
                    'WEBHOOK_COMPLETE',

                'reference' =>
                    $reference
            ]
        );


        http_response_code(200);

        echo 'OK';

    }
    catch (Throwable $e) {

        Logger::write(
            'advert_webhook_error',
            [
                'step' =>
                    'CONTROLLER_EXCEPTION',

                'message' =>
                    $e->getMessage(),

                'file' =>
                    $e->getFile(),

                'line' =>
                    $e->getLine(),

                'trace' =>
                    $e->getTraceAsString()
            ]
        );


        http_response_code(500);

        echo 'Webhook Error';
    }
}


    /**
     * ---------------------------------------------------------
     * Activate Advert Payment
     * ---------------------------------------------------------
     *
     * Handles seller-package payments only.
     *
     * Escrow payments are deliberately excluded.
     *
     * ---------------------------------------------------------
     */
    public function activatePayment(
        string $reference
    ): bool {

        try {

            $reference =
                trim(
                    $reference
                );


            if ($reference === '') {

                Logger::write(
                    'advert_activation_error',
                    [
                        'step' => 'REFERENCE_EMPTY'
                    ]
                );

                return false;
            }


            Logger::write(
                'advert_activation',
                [
                    'step'      => 'START',
                    'reference' => $reference
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Verify Payment With Paystack
            |--------------------------------------------------------------------------
            */

            $verifier =
                new PaystackVerifier();


            $verification =
                $verifier->verify(
                    $reference
                );


            Logger::write(
                'advert_activation',
                [
                    'step'        => 'PAYSTACK_VERIFIED',
                    'reference'   => $reference,
                    'verification' => $verification
                ]
            );


            if (
                !(
                    $verification['success']
                    ?? false
                )
            ) {

                Logger::write(
                    'advert_activation_error',
                    [
                        'step'      => 'PAYSTACK_FAILED',
                        'reference' => $reference
                    ]
                );

                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Verified Transaction
            |--------------------------------------------------------------------------
            */

            $transaction =
                $verification['data']
                ??
                [];


            if (!is_array($transaction) || $transaction === []) {

                Logger::write(
                    'advert_activation_error',
                    [
                        'step'      => 'TRANSACTION_MISSING',
                        'reference' => $reference
                    ]
                );

                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Ensure Transaction Is Successful
            |--------------------------------------------------------------------------
            */

            $transactionStatus =
                strtolower(
                    trim(
                        (string)(
                            $transaction['status']
                            ?? ''
                        )
                    )
                );


            if ($transactionStatus !== 'success') {

                Logger::write(
                    'advert_activation_error',
                    [
                        'step'   => 'TRANSACTION_NOT_SUCCESS',
                        'status' => $transactionStatus,
                        'reference' => $reference
                    ]
                );

                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Metadata
            |--------------------------------------------------------------------------
            */

            $metadata =
                $transaction['metadata']
                ??
                [];


            if (!is_array($metadata)) {

                $metadata = [];
            }


            Logger::write(
                'advert_activation',
                [
                    'step'      => 'METADATA',
                    'reference' => $reference,
                    'metadata'  => $metadata
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Payment Type
            |--------------------------------------------------------------------------
            */

            $paymentType =
                strtolower(
                    trim(
                        (string)(
                            $metadata['type']
                            ?? ''
                        )
                    )
                );


            /*
            |--------------------------------------------------------------------------
            | Reject Escrow
            |--------------------------------------------------------------------------
            |
            | There is now one authoritative escrow processor.
            |
            */

            if ($paymentType === 'escrow') {

                Logger::write(
                    'advert_activation',
                    [
                        'step'      => 'ESCROW_REJECTED_FROM_ADVERT_PROCESSOR',
                        'reference' => $reference,
                        'escrow_id' =>
                            $metadata['escrow_id']
                            ?? null
                    ]
                );

                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Load Advert Payment Record
            |--------------------------------------------------------------------------
            */

            $paymentModel =
                new AdvertUpgradePayment();


            $payment =
                $paymentModel->findByReference(
                    $reference
                );


            if (!$payment) {

                Logger::write(
                    'advert_activation_error',
                    [
                        'step'      => 'PAYMENT_RECORD_NOT_FOUND',
                        'reference' => $reference
                    ]
                );

                return false;
            }


            $paymentId =
                (int)(
                    $payment['id']
                    ?? 0
                );


            $userId =
                (int)(
                    $payment['user_id']
                    ?? 0
                );


            if ($paymentId <= 0 || $userId <= 0) {

                Logger::write(
                    'advert_activation_error',
                    [
                        'step'      => 'INVALID_PAYMENT_RECORD',
                        'reference' => $reference,
                        'payment_id' => $paymentId,
                        'user_id'    => $userId
                    ]
                );

                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Duplicate Protection
            |--------------------------------------------------------------------------
            */

            if (
                strtolower(
                    trim(
                        (string)(
                            $payment['status']
                            ?? ''
                        )
                    )
                )
                ===
                'paid'
            ) {

                Logger::write(
                    'advert_activation',
                    [
                        'step'      => 'ALREADY_ACTIVATED',
                        'reference' => $reference,
                        'payment_id' => $paymentId
                    ]
                );

                return true;
            }


            /*
            |--------------------------------------------------------------------------
            | Mark Payment Paid
            |--------------------------------------------------------------------------
            */

            $markedPaid =
                $paymentModel->markPaid(
                    $paymentId,
                    $transaction
                );


            if ($markedPaid === false) {

                Logger::write(
                    'advert_activation_error',
                    [
                        'step'      => 'MARK_PAYMENT_PAID_FAILED',
                        'reference' => $reference,
                        'payment_id' => $paymentId
                    ]
                );

                return false;
            }


            Logger::write(
                'advert_activation',
                [
                    'step'       => 'PAYMENT_MARKED_PAID',
                    'payment_id' => $paymentId,
                    'reference'  => $reference
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Load Seller Package
            |--------------------------------------------------------------------------
            */

            $packageModel =
                new SellerPackage();


            $package =
                $packageModel->find(
                    (int)(
                        $payment['package_id']
                        ?? 0
                    )
                );


            if (!$package) {

                Logger::write(
                    'advert_activation_error',
                    [
                        'step' =>
                            'PACKAGE_NOT_FOUND',

                        'package_id' =>
                            $payment['package_id']
                            ?? null,

                        'reference' =>
                            $reference
                    ]
                );

                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Create Seller Subscription
            |--------------------------------------------------------------------------
            */

            $subscription =
                new SellerSubscription();


            $old =
                $subscription->active(
                    $userId
                );


            if ($old) {

                Logger::write(
                    'advert_activation',
                    [
                        'step' =>
                            'CANCEL_OLD_SUBSCRIPTION',

                        'old_subscription_id' =>
                            $old['id'] ?? null,

                        'user_id' =>
                            $userId
                    ]
                );


                $subscription->cancel(
                    (int)$old['id']
                );
            }


            $duration =
                max(
                    1,
                    (int)(
                        $package['duration_days']
                        ?? 30
                    )
                );


            $starts =
                date(
                    'Y-m-d H:i:s'
                );


            $expires =
                date(
                    'Y-m-d H:i:s',
                    strtotime(
                        "+{$duration} days"
                    )
                );


            $created =
                $subscription->create(
                    [
                        'user_id' =>
                            $userId,

                        'package_id' =>
                            (int)$package['id'],

                        'daily_post_limit' =>
                            (int)(
                                $package['daily_post_limit']
                                ?? 0
                            ),

                        'payment_reference' =>
                            $reference,

                        'starts_at' =>
                            $starts,

                        'expires_at' =>
                            $expires,

                        'status' =>
                            'active'
                    ]
                );


            Logger::write(
                'advert_activation',
                [
                    'step'        => 'SUBSCRIPTION_CREATED',
                    'user_id'     => $userId,
                    'reference'   => $reference,
                    'subscription_create_result' => $created
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Reload Active Subscription
            |--------------------------------------------------------------------------
            */

            $active =
                $subscription->active(
                    $userId
                );


            if (!$active) {

                Logger::write(
                    'advert_activation_error',
                    [
                        'step'    => 'NEW_SUBSCRIPTION_NOT_FOUND',
                        'user_id' => $userId
                    ]
                );

                return false;
            }


            /*
            |--------------------------------------------------------------------------
            | Reset Daily Usage
            |--------------------------------------------------------------------------
            */

            $subscription->resetUsage(
                (int)$active['id']
            );


            Logger::write(
                'advert_activation',
                [
                    'step' =>
                        'USAGE_RESET',

                    'subscription_id' =>
                        $active['id'],

                    'user_id' =>
                        $userId
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Internal Payment Notification
            |--------------------------------------------------------------------------
            */

            $paymentNotification =
                new PaymentNotification();


            if (
                !$paymentNotification->exists(
                    $userId,
                    $reference
                )
            ) {

                $paymentNotification->create(
                    $userId,
                    $reference,
                    'Seller package activated.'
                );


                Logger::write(
                    'advert_activation',
                    [
                        'step' =>
                            'PAYMENT_NOTIFICATION_CREATED',

                        'user_id' =>
                            $userId
                    ]
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Bot Notification
            |--------------------------------------------------------------------------
            */

            $botNotification =
                new BotNotification();


            if (
                !$botNotification->exists(
                    $userId,
                    'seller_upgrade',
                    $reference
                )
            ) {

                $packageName =
                    (string)(
                        $package['name']
                        ?? 'Seller'
                    );


                $botNotification->create(
                    $userId,
                    'seller_upgrade',
                    'Seller Package Activated',

                    "Your {$packageName} package has been activated successfully.\n\n"
                    .
                    "Your advert quota has been reset.\n\n"
                    .
                    "You can now create adverts immediately.\n\n"
                    .
                    "Reply SELL to create your first advert.",

                    $reference
                );


                Logger::write(
                    'advert_activation',
                    [
                        'step' =>
                            'BOT_NOTIFICATION_CREATED',

                        'user_id' =>
                            $userId,

                        'reference' =>
                            $reference
                    ]
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Immediate Platform Notification
            |--------------------------------------------------------------------------
            */

            $this->sendImmediateNotification(
                $payment,
                $package,
                $reference
            );


            /*
            |--------------------------------------------------------------------------
            | Complete
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'advert_activation',
                [
                    'step'      => 'SELLER_PACKAGE_COMPLETE',
                    'reference' => $reference,
                    'user_id'   => $userId
                ]
            );


            return true;

        }
        catch (Throwable $e) {

            Logger::write(
                'advert_activation_exception',
                [
                    'step'    => 'ACTIVATE_EXCEPTION',
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString()
                ]
            );

            return false;
        }
    }


    /**
     * ---------------------------------------------------------
     * Immediate Platform Notification
     * ---------------------------------------------------------
     */
    protected function sendImmediateNotification(
        array $payment,
        array $package,
        string $reference
    ): void {

        try {

            $platform =
                strtolower(
                    trim(
                        (string)(
                            $payment['platform']
                            ?? 'telegram'
                        )
                    )
                );


            $platformId =
                trim(
                    (string)(
                        $payment['platform_id']
                        ?? ''
                    )
                );


            if ($platformId === '') {

                Logger::write(
                    'advert_activation',
                    [
                        'step' =>
                            'NO_PLATFORM_ID',
                        'reference' =>
                            $reference
                    ]
                );

                return;
            }


            $packageName =
                (string)(
                    $package['name']
                    ?? 'Seller'
                );


            $message =
                "Your {$packageName} seller package has been activated successfully.\n\n"
                .
                "Your advert quota has been reset.\n\n"
                .
                "Reply SELL to create your first advert.";


            $reply =
                ReplyFactory::make(
                    $platform
                );


            $reply->text(
                $platformId,
                $message
            );


            Logger::write(
                'advert_activation',
                [
                    'step'      => 'PLATFORM_MESSAGE_SENT',
                    'platform'  => $platform,
                    'platform_id' => $platformId,
                    'reference' => $reference
                ]
            );

        }
        catch (Throwable $e) {

            /*
             * Payment activation has already succeeded.
             * A notification failure must not reverse it.
             */

            Logger::write(
                'advert_activation_error',
                [
                    'step'      => 'PLATFORM_MESSAGE_FAILED',
                    'reference' => $reference,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine()
                ]
            );
        }
    }
}
