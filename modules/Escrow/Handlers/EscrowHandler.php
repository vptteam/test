<?php

declare(strict_types=1);

namespace Modules\Escrow\Handlers;

use Core\Logger;
use Modules\Escrow\Models\Escrow;
use Services\Escrow\EscrowService;
use Services\Payments\PaystackGateway;
use Throwable;

class EscrowHandler
{
    /**
     * ---------------------------------------------------------
     * Buyer Starts Escrow
     *
     * Command:
     *
     * ESCROW LISTING_REFERENCE
     * ---------------------------------------------------------
     */
    public function start(
        $reply,
        array $user,
        array $message,
        string $text
    ): void {

        $phone = $message['phone'] ?? '';

        $userId = (int)(
            $user['id'] ?? 0
        );


        try {

            Logger::write(
                'escrow_handler',
                [
                    'step'    => 'START_HANDLER',
                    'user_id' => $userId,
                    'phone'   => $phone,
                    'text'    => $text
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Parse Command
            |--------------------------------------------------------------------------
            */

            $parts = preg_split(
                '/\s+/',
                trim($text)
            );


            if (
                empty($parts)
                || empty($parts[1])
            ) {

                Logger::write(
                    'escrow_handler',
                    [
                        'step'    => 'REFERENCE_MISSING',
                        'user_id' => $userId
                    ]
                );


                $reply->text(
                    $phone,

                    "🛡 ESCROW\n\n" .

                    "Buying from someone you don’t know?\n\n" .

                    "Use Sendam Escrow to protect your payment.\n\n" .

                    "🔒 Your money is held securely until you confirm you've received your item. Only then is the seller paid.\n\n" .

                    "How it works:\n\n" .

                    "1️⃣ Contact the seller and confirm the item is available.\n" .

                    "2️⃣ Copy the item's Listing Reference from the item posted (e.g. SDM-XXXXXX).\n" .

                    "3️⃣ Start Escrow by sending:\n\n" .

                    "ESCROW SDM-XXXXXXX\n\n" .

                    "We'll verify the listing and guide you through the secure payment process."
                );


                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Listing Reference
            |--------------------------------------------------------------------------
            */

            $listingReference = strtoupper(
                trim($parts[1])
            );


            Logger::write(
                'escrow_handler',
                [
                    'step'              => 'REFERENCE_PARSED',
                    'user_id'           => $userId,
                    'listing_reference' => $listingReference
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Create Escrow
            |--------------------------------------------------------------------------
            */

            $service = new EscrowService();


            Logger::write(
                'escrow_handler',
                [
                    'step' => 'CALLING_ESCROW_SERVICE'
                ]
            );


            $escrow = $service->create(

                $listingReference,

                $userId,

                $phone

            );


            Logger::write(
                'escrow_handler',
                [
                    'step'              => 'ESCROW_CREATE_RESULT',
                    'listing_reference' => $listingReference,
                    'user_id'           => $userId,
                    'result_type'       => gettype($escrow),
                    'result'            => $escrow
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Escrow Creation Failed
            |--------------------------------------------------------------------------
            */

            if (!$escrow) {

                Logger::write(
                    'escrow_handler_error',
                    [
                        'step'              => 'ESCROW_CREATE_FAILED',
                        'listing_reference' => $listingReference,
                        'user_id'           => $userId
                    ]
                );


                $reply->text(
                    $phone,

                    "❌ Unable to create escrow.\n\n" .

                    "Please check the listing reference and make sure the item is still available."
                );


                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Extract Escrow Data
            |--------------------------------------------------------------------------
            */

            $escrowId = (int)(
                $escrow['id'] ?? 0
            );


            $escrowReference =
                (string)(
                    $escrow['reference'] ?? ''
                );


            $amount = round(
                (float)(
                    $escrow['amount'] ?? 0
                ),
                2
            );


            $escrowFee = round(
                (float)(
                    $escrow['escrow_fee'] ?? 0
                ),
                2
            );


            $sellerAmount = round(
                (float)(
                    $escrow['seller_amount'] ?? 0
                ),
                2
            );


            /*
            |--------------------------------------------------------------------------
            | Runtime Payment Amount
            |--------------------------------------------------------------------------
            |
            | EscrowService calculates this using the database
            | settings.
            |
            */

            $paymentAmount = round(
                (float)(
                    $escrow['payment_amount']
                    ?? $amount
                ),
                2
            );


            $paystackFee = round(
                (float)(
                    $escrow['paystack_fee'] ?? 0
                ),
                2
            );


            $escrowFeePayer = strtolower(
                trim(
                    (string)(
                        $escrow['escrow_fee_payer']
                        ?? 'buyer'
                    )
                )
            );


            $paystackFeePayer = strtolower(
                trim(
                    (string)(
                        $escrow['paystack_fee_payer']
                        ?? 'buyer'
                    )
                )
            );


            Logger::write(
                'escrow_handler',
                [
                    'step'               => 'ESCROW_DATA_READY',
                    'escrow_id'          => $escrowId,
                    'reference'          => $escrowReference,
                    'amount'             => $amount,
                    'escrow_fee'         => $escrowFee,
                    'escrow_fee_payer'   => $escrowFeePayer,
                    'paystack_fee'       => $paystackFee,
                    'paystack_fee_payer' => $paystackFeePayer,
                    'seller_amount'      => $sellerAmount,
                    'payment_amount'     => $paymentAmount
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Validate Amount
            |--------------------------------------------------------------------------
            */

            if (
                $escrowId <= 0
                || $amount <= 0
                || $paymentAmount <= 0
            ) {

                Logger::write(
                    'escrow_handler_error',
                    [
                        'step'          => 'INVALID_ESCROW_AMOUNT',
                        'escrow_id'     => $escrowId,
                        'amount'        => $amount,
                        'payment_amount' => $paymentAmount
                    ]
                );


                $reply->text(
                    $phone,

                    "❌ Unable to determine the correct payment amount.\n\n" .
                    "Please try again later."
                );


                return;
            }


            Logger::write(
                'escrow_handler',
                [
                    'step'      => 'ESCROW_CREATED',
                    'escrow_id' => $escrowId,
                    'reference' => $escrowReference,
                    'buyer_id'  => $escrow['buyer_id'] ?? null,
                    'seller_id' => $escrow['seller_id'] ?? null,
                    'listing_id' => $escrow['listing_id'] ?? null
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Buyer Email
            |--------------------------------------------------------------------------
            */

            $email = trim(
                (string)(
                    $user['email'] ?? ''
                )
            );


            if (
                empty($email)
                || !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                $email = PAYSTACK_DEFAULT_EMAIL;

            }


            Logger::write(
                'escrow_handler',
                [
                    'step'  => 'BUYER_EMAIL_READY',
                    'email' => $email
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Initialize Paystack
            |--------------------------------------------------------------------------
            */

            $gateway = new PaystackGateway();


            Logger::write(
                'escrow_handler',
                [
                    'step'          => 'PAYMENT_INITIALIZATION_START',
                    'reference'     => $escrowReference,
                    'item_amount'   => $amount,
                    'escrow_fee'    => $escrowFee,
                    'paystack_fee'  => $paystackFee,
                    'payment_amount' => $paymentAmount
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | IMPORTANT
            |--------------------------------------------------------------------------
            |
            | Paystack receives PAYMENT AMOUNT, not the raw listing amount.
            |
            */

            $payment = $gateway->initialize(

                (int)round(
                    $paymentAmount
                ),

                $email,

                $escrowReference,

                APP_URL .
                '/payment/paystack/advert/callback',

                [
                    'type' => 'escrow',

                    'escrow_id' => $escrowId,

                    'buyer_id' =>
                        (int)(
                            $escrow['buyer_id']
                            ?? $userId
                        ),

                    'seller_id' =>
                        (int)(
                            $escrow['seller_id']
                            ?? 0
                        ),

                    'listing_id' =>
                        (int)(
                            $escrow['listing_id']
                            ?? 0
                        ),

                    'reference' =>
                        $escrowReference,

                    /*
                    |--------------------------------------------------------------------------
                    | Fee Information
                    |--------------------------------------------------------------------------
                    */

                    'item_amount' =>
                        $amount,

                    'escrow_fee' =>
                        $escrowFee,

                    'escrow_fee_payer' =>
                        $escrowFeePayer,

                    'paystack_fee' =>
                        $paystackFee,

                    'paystack_fee_payer' =>
                        $paystackFeePayer,

                    'payment_amount' =>
                        $paymentAmount,

                    'seller_amount' =>
                        $sellerAmount
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Log Paystack Response
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_handler',
                [
                    'step'           => 'PAYMENT_INITIALIZATION_RESULT',
                    'reference'      => $escrowReference,
                    'payment_result' => $payment
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Payment Initialization Failed
            |--------------------------------------------------------------------------
            */

            if (
                !($payment['success'] ?? false)
            ) {

                Logger::error(
                    'escrow_handler',
                    [
                        'step'      => 'PAYMENT_FAILED',
                        'reference' => $escrowReference,
                        'message'   =>
                            $payment['message']
                            ?? null
                    ]
                );


                $reply->text(
                    $phone,

                    "❌ Unable to initialize payment.\n\n" .

                    (
                        $payment['message']
                        ?? 'Unknown payment error.'
                    )
                );


                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Payment URL
            |--------------------------------------------------------------------------
            */

            $authorizationUrl =
                trim(
                    (string)(
                        $payment['authorization_url']
                        ?? ''
                    )
                );


            if (
                empty($authorizationUrl)
            ) {

                Logger::error(
                    'escrow_handler',
                    [
                        'step'      => 'PAYMENT_URL_MISSING',
                        'reference' => $escrowReference
                    ]
                );


                $reply->text(
                    $phone,

                    "❌ Payment link was not generated.\n\n" .
                    "Please try again."
                );


                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Save Payment Reference
            |--------------------------------------------------------------------------
            */

            $paymentReference =
                trim(
                    (string)(
                        $payment['reference']
                        ?? ''
                    )
                );


            $escrowModel = new Escrow();


            $updated = $escrowModel->update(

                $escrowId,

                [
                    'payment_reference' =>
                        $paymentReference
                ]

            );


            Logger::write(
                'escrow_handler',
                [
                    'step'              => 'PAYMENT_REFERENCE_SAVED',
                    'escrow_id'         => $escrowId,
                    'reference'         => $escrowReference,
                    'payment_reference' => $paymentReference,
                    'updated'           => $updated
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Build Fee Description
            |--------------------------------------------------------------------------
            */

            $feeLines = '';


            if (
                $escrowFeePayer === 'buyer'
                && $escrowFee > 0
            ) {

                $feeLines .=
                    "🛡 Sendam Escrow Fee: ₦" .
                    number_format(
                        $escrowFee,
                        2
                    ) .
                    "\n";
            }


            if (
                $escrowFeePayer === 'seller'
                && $escrowFee > 0
            ) {

                $feeLines .=
                    "🛡 Sendam Escrow Fee: ₦" .
                    number_format(
                        $escrowFee,
                        2
                    ) .
                    " (deducted from seller)\n";
            }


            if (
                $paystackFeePayer === 'buyer'
                && $paystackFee > 0
            ) {

                $feeLines .=
                    "💳 Payment Processing Fee: ₦" .
                    number_format(
                        $paystackFee,
                        2
                    ) .
                    "\n";
            }


            /*
            |--------------------------------------------------------------------------
            | Send Payment Link
            |--------------------------------------------------------------------------
            */

            $messageText =

                "🛡 ESCROW CREATED\n\n" .

                "Reference: " .
                $escrowReference .
                "\n\n" .

                "Item Amount: ₦" .
                number_format(
                    $amount,
                    2
                ) .
                "\n" .


                $feeLines .


                "\n💰 Total Payment: ₦" .
                number_format(
                    $paymentAmount,
                    2
                ) .


                "\n\n" .


                "🔒 Your payment is protected by SENDAM Escrow.\n\n" .


                "The seller will only receive the seller's amount after the escrow conditions are satisfied.\n\n" .


                "Complete your secure payment using the link below:\n\n" .


                $authorizationUrl;


            $reply->text(
                $phone,
                $messageText
            );


            Logger::write(
                'escrow_handler',
                [
                    'step'           => 'PAYMENT_LINK_SENT',
                    'escrow_id'      => $escrowId,
                    'reference'      => $escrowReference,
                    'payment_amount' => $paymentAmount,
                    'authorization_url' => $authorizationUrl
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | Complete
            |--------------------------------------------------------------------------
            */

            Logger::write(
                'escrow_handler',
                [
                    'step'      => 'COMPLETE',
                    'escrow_id' => $escrowId,
                    'reference' => $escrowReference,
                    'buyer_id'  =>
                        $escrow['buyer_id']
                        ?? $userId
                ]
            );


        } catch (Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | Exception
            |--------------------------------------------------------------------------
            */

            Logger::error(
                'escrow_handler',
                [
                    'step'    => 'EXCEPTION',
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString()
                ]
            );


            /*
            |--------------------------------------------------------------------------
            | User-Friendly Error
            |--------------------------------------------------------------------------
            */

            try {

                $reply->text(
                    $phone,

                    "⚠️ Unable to create escrow.\n\n" .
                    "Please try again later."
                );


            } catch (Throwable $ignore) {

                Logger::error(
                    'escrow_handler',
                    [
                        'step' => 'REPLY_FAILED',
                        'message' =>
                            $ignore->getMessage()
                    ]
                );
            }
        }
    }
}