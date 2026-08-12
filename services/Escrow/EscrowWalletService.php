<?php

declare(strict_types=1);

namespace Services\Escrow;

use Core\Logger;
use Modules\Escrow\Models\EscrowWallet;
use Services\Payments\PaystackTransfer;
use Throwable;

class EscrowWalletService
{
    protected EscrowWallet $wallet;

    protected PaystackTransfer $paystack;

    public function __construct()
    {
        $this->wallet = new EscrowWallet();

        $this->paystack = new PaystackTransfer();
    }

    /**
     * --------------------------------------------------------------------------
     * Resolve Bank Account
     * --------------------------------------------------------------------------
     *
     * Resolves:
     *
     * - Account name
     * - Account number
     * - Bank code
     *
     * Bank name is resolved separately from the Paystack bank list because
     * account resolution should not be relied upon to return bank_name.
     */
    public function resolveAccount(
        string $bankCode,
        string $accountNumber
    ): array {

        try {

            Logger::write(
                'escrow_wallet',
                [
                    'step'           => 'RESOLVE_ACCOUNT',
                    'bank_code'      => $bankCode,
                    'account_number' => $accountNumber
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Resolve account through Paystack
            |--------------------------------------------------------------------------
            */

            $result = $this->paystack->resolveAccount(
                $accountNumber,
                $bankCode
            );

            Logger::write(
                'escrow_wallet',
                [
                    'step'   => 'RESOLVE_RESPONSE',
                    'success' => $result['success'] ?? false,
                    'data'    => $result['data'] ?? null
                ]
            );

            if (!($result['success'] ?? false)) {

                return [
                    'success' => false,
                    'message' =>
                        $result['message']
                        ??
                        'Unable to verify bank account.'
                ];
            }

            $data = $result['data'] ?? [];

            /*
            |--------------------------------------------------------------------------
            | Account Name
            |--------------------------------------------------------------------------
            */

            $accountName = trim(
                (string)(
                    $data['account_name']
                    ?? ''
                )
            );

            if ($accountName === '') {

                Logger::write(
                    'escrow_wallet',
                    [
                        'step' => 'ACCOUNT_NAME_MISSING',
                        'bank_code' => $bankCode,
                        'account_number' => $accountNumber,
                        'response' => $data
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Unable to retrieve the account name from the bank.'
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Bank Name
            |--------------------------------------------------------------------------
            |
            | IMPORTANT:
            |
            | Do not depend on resolveAccount() returning bank_name.
            | Resolve the bank name from Paystack's bank list.
            |
            */

            $bankName = $this->resolveBankName(
                $bankCode
            );

            if ($bankName === '') {

                Logger::write(
                    'escrow_wallet',
                    [
                        'step'      => 'BANK_NAME_NOT_FOUND',
                        'bank_code' => $bankCode
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Unable to determine the bank name for bank code '
                        . $bankCode
                ];
            }

            Logger::write(
                'escrow_wallet',
                [
                    'step'           => 'ACCOUNT_RESOLVED',
                    'bank_code'      => $bankCode,
                    'bank_name'      => $bankName,
                    'account_number' => $accountNumber,
                    'account_name'   => $accountName
                ]
            );

            return [

                'success' => true,

                'bank_code' => $bankCode,

                'bank_name' => $bankName,

                'account_number' => $accountNumber,

                'account_name' => $accountName

            ];

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'           => 'RESOLVE_FAILED',
                    'bank_code'      => $bankCode,
                    'account_number' => $accountNumber,
                    'message'        => $e->getMessage(),
                    'file'           => $e->getFile(),
                    'line'           => $e->getLine()
                ]
            );

            return [

                'success' => false,

                'message' =>
                    'Unable to resolve bank account.'

            ];
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Resolve Bank Name
     * --------------------------------------------------------------------------
     */
    public function resolveBankName(
        string $bankCode
    ): string {

        try {

            $bankCode = trim($bankCode);

            if ($bankCode === '') {
                return '';
            }

            Logger::write(
                'escrow_wallet',
                [
                    'step'      => 'RESOLVE_BANK_NAME',
                    'bank_code' => $bankCode
                ]
            );

            $banks = $this->banks();

            if (!($banks['success'] ?? false)) {

                Logger::write(
                    'escrow_wallet_error',
                    [
                        'step'      => 'BANK_LIST_FAILED',
                        'bank_code' => $bankCode,
                        'response'  => $banks
                    ]
                );

                return '';
            }

            foreach (($banks['data'] ?? []) as $bank) {

                $code = trim(
                    (string)(
                        $bank['code']
                        ?? ''
                    )
                );

                if ($code === $bankCode) {

                    $name = trim(
                        (string)(
                            $bank['name']
                            ?? ''
                        )
                    );

                    Logger::write(
                        'escrow_wallet',
                        [
                            'step'      => 'BANK_NAME_RESOLVED',
                            'bank_code' => $bankCode,
                            'bank_name' => $name
                        ]
                    );

                    return $name;
                }
            }

            return '';

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'RESOLVE_BANK_NAME_FAILED',
                    'bank_code' => $bankCode,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine()
                ]
            );

            return '';
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Save Seller Bank
     * --------------------------------------------------------------------------
     */
    public function saveBank(
        int $sellerId,
        string $bankCode,
        string $bankName,
        string $accountNumber,
        string $accountName
    ): bool {

        try {

            Logger::write(
                'escrow_wallet',
                [
                    'step'           => 'SAVE_BANK',
                    'seller_id'      => $sellerId,
                    'bank_code'      => $bankCode,
                    'bank_name'      => $bankName,
                    'account_number' => $accountNumber,
                    'account_name'   => $accountName
                ]
            );

            $existing = $this->wallet->findBySeller(
                $sellerId
            );

            $data = [

                'seller_id' => $sellerId,

                'bank_code' => $bankCode,

                'bank_name' => $bankName,

                'account_number' => $accountNumber,

                'account_name' => $accountName,

                'status' => 'verified',

                'verified_at' => date(
                    'Y-m-d H:i:s'
                )

            ];

            /*
            |--------------------------------------------------------------------------
            | Update Existing Wallet
            |--------------------------------------------------------------------------
            */

            if ($existing) {

                $updated = $this->wallet->update(
                    (int)$existing['id'],
                    $data
                );

                Logger::write(
                    'escrow_wallet',
                    [
                        'step'      => 'SAVE_BANK_UPDATED',
                        'seller_id' => $sellerId,
                        'wallet_id' => $existing['id'],
                        'success'   => $updated
                    ]
                );

                return $updated;
            }

            /*
            |--------------------------------------------------------------------------
            | Create New Wallet
            |--------------------------------------------------------------------------
            */

            $created = $this->wallet->create(
                $data
            );

            Logger::write(
                'escrow_wallet',
                [
                    'step'      => 'SAVE_BANK_CREATED',
                    'seller_id' => $sellerId,
                    'success'   => $created
                ]
            );

            return $created;

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'SAVE_BANK_FAILED',
                    'seller_id' => $sellerId,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine()
                ]
            );

            return false;
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Find Seller Wallet
     * --------------------------------------------------------------------------
     */
    public function findWallet(
        int $sellerId
    ): ?array {

        return $this->wallet->findBySeller(
            $sellerId
        );
    }

    /**
     * --------------------------------------------------------------------------
     * Wallet Ready For Payout
     * --------------------------------------------------------------------------
     */
    public function walletReady(
        int $sellerId
    ): bool {

        $wallet = $this->findWallet(
            $sellerId
        );

        if (!$wallet) {
            return false;
        }

        if (
            ($wallet['status'] ?? '')
            !== 'verified'
        ) {
            return false;
        }

        if (
            empty(
                $wallet['verified_at']
            )
        ) {
            return false;
        }

        if (
            empty(
                $wallet['recipient_code']
            )
        ) {
            return false;
        }

        return true;
    }

    /**
     * --------------------------------------------------------------------------
     * Update Recipient Code
     * --------------------------------------------------------------------------
     */
    public function updateRecipientCode(
        int $sellerId,
        string $recipientCode
    ): bool {

        try {

            $wallet = $this->findWallet(
                $sellerId
            );

            if (!$wallet) {

                Logger::write(
                    'escrow_wallet_error',
                    [
                        'step'      => 'RECIPIENT_WALLET_NOT_FOUND',
                        'seller_id' => $sellerId
                    ]
                );

                return false;
            }

            return $this->wallet->update(
                (int)$wallet['id'],
                [
                    'recipient_code' => $recipientCode
                ]
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'UPDATE_RECIPIENT_FAILED',
                    'seller_id' => $sellerId,
                    'message'   => $e->getMessage(),
                    'line'      => $e->getLine()
                ]
            );

            return false;
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Remove Seller Wallet
     * --------------------------------------------------------------------------
     */
    public function removeWallet(
        int $sellerId
    ): bool {

        try {

            $wallet = $this->findWallet(
                $sellerId
            );

            if (!$wallet) {
                return false;
            }

            return $this->wallet->delete(
                (int)$wallet['id']
            );

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'REMOVE_WALLET_FAILED',
                    'seller_id' => $sellerId,
                    'message'   => $e->getMessage(),
                    'line'      => $e->getLine()
                ]
            );

            return false;
        }
    }

    /**
     * --------------------------------------------------------------------------
     * List Supported Banks
     * --------------------------------------------------------------------------
     */
    public function banks(): array
    {
        return $this->paystack->banks();
    }

    /**
     * --------------------------------------------------------------------------
     * Register Seller Wallet
     * --------------------------------------------------------------------------
     *
     * Flow:
     *
     * 1. Resolve bank name
     * 2. Resolve account name
     * 3. Create Paystack recipient
     * 4. Save bank information
     * 5. Save recipient code
     * 6. Return complete wallet
     */
    public function registerWallet(
        int $sellerId,
        string $bankCode,
        string $accountNumber
    ): array {

        try {

            Logger::write(
                'escrow_wallet',
                [
                    'step'           => 'REGISTER_START',
                    'seller_id'      => $sellerId,
                    'bank_code'      => $bankCode,
                    'account_number' => $accountNumber
                ]
            );

            $bankCode = trim($bankCode);

            $accountNumber = trim(
                $accountNumber
            );

            /*
            |--------------------------------------------------------------------------
            | Validate
            |--------------------------------------------------------------------------
            */

            if (
                !preg_match(
                    '/^[0-9]{3}$/',
                    $bankCode
                )
            ) {

                return [
                    'success' => false,
                    'message' => 'Invalid bank code.'
                ];
            }

            if (
                !preg_match(
                    '/^[0-9]{10}$/',
                    $accountNumber
                )
            ) {

                return [
                    'success' => false,
                    'message' => 'Invalid account number.'
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve Account
            |--------------------------------------------------------------------------
            */

            $resolved = $this->resolveAccount(
                $bankCode,
                $accountNumber
            );

            if (!($resolved['success'] ?? false)) {

                Logger::write(
                    'escrow_wallet',
                    [
                        'step'      => 'RESOLVE_FAILED',
                        'seller_id' => $sellerId,
                        'message'   => $resolved['message'] ?? null
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        $resolved['message']
                        ??
                        'Unable to verify bank account.'
                ];
            }

            $bankName = trim(
                (string)(
                    $resolved['bank_name']
                    ?? ''
                )
            );

            $accountName = trim(
                (string)(
                    $resolved['account_name']
                    ?? ''
                )
            );

            /*
            |--------------------------------------------------------------------------
            | Safety Check
            |--------------------------------------------------------------------------
            */

            if ($bankName === '') {

                return [
                    'success' => false,
                    'message' =>
                        'Bank name could not be determined.'
                ];
            }

            if ($accountName === '') {

                return [
                    'success' => false,
                    'message' =>
                        'Account name could not be determined.'
                ];
            }

            Logger::write(
                'escrow_wallet',
                [
                    'step'         => 'ACCOUNT_VERIFIED',
                    'seller_id'    => $sellerId,
                    'bank_code'    => $bankCode,
                    'bank_name'    => $bankName,
                    'account_name' => $accountName
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Create Paystack Transfer Recipient
            |--------------------------------------------------------------------------
            */

            $recipient = $this->paystack->createRecipient(
                $accountName,
                $accountNumber,
                $bankCode
            );

            if (!($recipient['success'] ?? false)) {

                Logger::write(
                    'escrow_wallet',
                    [
                        'step'      => 'CREATE_RECIPIENT_FAILED',
                        'seller_id' => $sellerId,
                        'response'  => $recipient
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        $recipient['message']
                        ??
                        'Unable to create transfer recipient.'
                ];
            }

            $recipientCode =
                trim(
                    (string)(
                        $recipient['data']['recipient_code']
                        ?? ''
                    )
                );

            if ($recipientCode === '') {

                Logger::write(
                    'escrow_wallet_error',
                    [
                        'step'      => 'RECIPIENT_CODE_MISSING',
                        'seller_id' => $sellerId,
                        'response'  => $recipient
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        'Recipient code was not returned by Paystack.'
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Save Wallet
            |--------------------------------------------------------------------------
            */

            $saved = $this->saveBank(
                $sellerId,
                $bankCode,
                $bankName,
                $accountNumber,
                $accountName
            );

            if (!$saved) {

                return [
                    'success' => false,
                    'message' =>
                        'Unable to save seller wallet.'
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Save Recipient Code
            |--------------------------------------------------------------------------
            */

            $recipientSaved =
                $this->updateRecipientCode(
                    $sellerId,
                    $recipientCode
                );

            if (!$recipientSaved) {

                return [
                    'success' => false,
                    'message' =>
                        'Unable to save recipient code.'
                ];
            }

            /*
            |--------------------------------------------------------------------------
            | Load Final Wallet
            |--------------------------------------------------------------------------
            */

            $wallet = $this->findWallet(
                $sellerId
            );

            if (!$wallet) {

                return [
                    'success' => false,
                    'message' =>
                        'Wallet was saved but could not be retrieved.'
                ];
            }

            Logger::write(
                'escrow_wallet',
                [
                    'step'           => 'REGISTER_COMPLETE',
                    'seller_id'      => $sellerId,
                    'wallet_id'      => $wallet['id'] ?? null,
                    'bank_code'      => $wallet['bank_code'] ?? null,
                    'bank_name'      => $wallet['bank_name'] ?? null,
                    'account_name'   => $wallet['account_name'] ?? null,
                    'recipient_code' => $wallet['recipient_code'] ?? null,
                    'status'         => $wallet['status'] ?? null
                ]
            );

            return [

                'success' => true,

                'message' =>
                    'Wallet registered successfully.',

                'wallet' => $wallet

            ];
        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'REGISTER_EXCEPTION',
                    'seller_id' => $sellerId,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine()
                ]
            );

            return [

                'success' => false,

                'message' =>
                    'Unable to register wallet.'

            ];
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Send Escrow Payout
     * --------------------------------------------------------------------------
     */
    public function payout(
        int $sellerId,
        float $amount,
        string $reference
    ): array {

        try {

            Logger::write(
                'escrow_wallet',
                [
                    'step'      => 'PAYOUT_START',
                    'seller_id' => $sellerId,
                    'amount'    => $amount,
                    'reference' => $reference
                ]
            );

            if ($amount <= 0) {

                return [
                    'success' => false,
                    'message' => 'Invalid payout amount.'
                ];
            }

            $wallet = $this->findWallet(
                $sellerId
            );

            if (!$wallet) {

                return [
                    'success' => false,
                    'message' =>
                        'Seller has not registered a bank account.'
                ];
            }

            if (
                ($wallet['status'] ?? '')
                !== 'verified'
            ) {

                return [
                    'success' => false,
                    'message' =>
                        'Seller bank account is not verified.'
                ];
            }

            if (
                empty(
                    $wallet['recipient_code']
                )
            ) {

                return [
                    'success' => false,
                    'message' =>
                        'Seller transfer recipient has not been created.'
                ];
            }

            Logger::write(
                'escrow_wallet',
                [
                    'step'           => 'INITIATE_TRANSFER',
                    'seller_id'      => $sellerId,
                    'recipient_code' => $wallet['recipient_code'],
                    'bank_name'      => $wallet['bank_name'] ?? null,
                    'account_name'   => $wallet['account_name'] ?? null,
                    'account_number' => $wallet['account_number'] ?? null,
                    'amount'         => $amount,
                    'reference'      => $reference
                ]
            );

            $transfer = $this->paystack->transfer(
                $wallet['recipient_code'],
                $amount,
                $reference,
                'SENDAM Escrow Payment'
            );

            if (!($transfer['success'] ?? false)) {

                Logger::write(
                    'escrow_wallet',
                    [
                        'step'     => 'TRANSFER_FAILED',
                        'seller_id' => $sellerId,
                        'response' => $transfer
                    ]
                );

                return [
                    'success' => false,
                    'message' =>
                        $transfer['message']
                        ??
                        'Unable to initiate payout.'
                ];
            }

            $data = $transfer['data'] ?? [];

            Logger::write(
                'escrow_wallet',
                [
                    'step'          => 'TRANSFER_CREATED',
                    'seller_id'     => $sellerId,
                    'transfer_code' =>
                        $data['transfer_code'] ?? null,
                    'status' =>
                        $data['status'] ?? null
                ]
            );

            if (
                isset($data['status'])
                &&
                strtolower(
                    (string)$data['status']
                ) === 'otp'
            ) {

                return [

                    'success' => false,

                    'otp_required' => true,

                    'transfer_code' =>
                        $data['transfer_code'] ?? null,

                    'message' =>
                        'Paystack requires OTP confirmation before this payout can be completed.'

                ];
            }

            return [

                'success' => true,

                'message' =>
                    'Payout initiated successfully.',

                'transfer' => $data

            ];

        }

        catch (Throwable $e) {

            Logger::write(
                'escrow_wallet_error',
                [
                    'step'      => 'PAYOUT_EXCEPTION',
                    'seller_id' => $sellerId,
                    'message'   => $e->getMessage(),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine()
                ]
            );

            return [

                'success' => false,

                'message' =>
                    'Unable to process payout.'

            ];
        }
    }
}