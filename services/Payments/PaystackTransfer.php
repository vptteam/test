<?php

declare(strict_types=1);

namespace Services\Payments;

use Core\Logger;
use Throwable;

class PaystackTransfer
{
    protected string $secret;

    protected string $baseUrl;

    public function __construct()
    {
        $this->secret = PAYSTACK_SECRET_KEY;

        $this->baseUrl = rtrim(
            PAYSTACK_BASE_URL,
            '/'
        );
    }

    /**
     * ---------------------------------------------------------
     * Send Request
     * ---------------------------------------------------------
     */
    protected function request(
        string $method,
        string $endpoint,
        array $payload = []
    ): array {

        try {

            $url = $this->baseUrl . $endpoint;

            $ch = curl_init($url);

            curl_setopt(
                $ch,
                CURLOPT_RETURNTRANSFER,
                true
            );

            curl_setopt(
                $ch,
                CURLOPT_HTTPHEADER,
                [
                    'Authorization: Bearer '.$this->secret,
                    'Content-Type: application/json'
                ]
            );

            if ($method === 'POST') {

                curl_setopt(
                    $ch,
                    CURLOPT_POST,
                    true
                );

                curl_setopt(
                    $ch,
                    CURLOPT_POSTFIELDS,
                    json_encode($payload)
                );
            }
            
            if ($method === 'GET') {

    curl_setopt(
        $ch,
        CURLOPT_HTTPGET,
        true
    );

}

            $response = curl_exec($ch);

            $http = curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

            $error = curl_error($ch);

            curl_close($ch);

            Logger::write(
                'paystack_transfer',
                [
                    'method'   => $method,
                    'endpoint' => $endpoint,
                    'http'     => $http,
                    'payload'  => $payload,
                    'response' => $response,
                    'error'    => $error
                ]
            );

            if ($error) {

                return [

                    'success' => false,

                    'message' => $error

                ];

            }

            $decoded = json_decode(
                $response,
                true
            );
            
            if (!is_array($decoded)) {

    return [

        'success' => false,

        'message' => 'Invalid Paystack response.'

    ];

}

            if (!($decoded['status'] ?? false)) {

                return [

                    'success' => false,

                    'message' => $decoded['message']
                        ?? 'Paystack request failed.'

                ];

            }

            return [

                'success' => true,

                'data' => $decoded['data']

            ];

        }

        catch (Throwable $e) {

            Logger::write(
                'paystack_transfer_error',
                [
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine()
                ]
            );

            return [

                'success' => false,

                'message' => $e->getMessage()

            ];

        }

    }

    /**
     * ---------------------------------------------------------
     * Create Transfer Recipient
     * ---------------------------------------------------------
     */
    public function createRecipient(
        string $name,
        string $accountNumber,
        string $bankCode,
        string $currency = 'NGN'
    ): array {

        return $this->request(

            'POST',

            '/transferrecipient',

            [

                'type' => 'nuban',

                'name' => $name,

                'account_number' => $accountNumber,

                'bank_code' => $bankCode,

                'currency' => $currency

            ]

        );

    }

    /**
     * ---------------------------------------------------------
     * Initiate Transfer
     * ---------------------------------------------------------
     */
    public function transfer(
        string $recipientCode,
        float $amount,
        string $reference,
        string $reason = 'Escrow Payment'
    ): array {

        return $this->request(

            'POST',

            '/transfer',

            [

                'source' => 'balance',

                'amount' => (int) round($amount * 100),

                'recipient' => $recipientCode,

                'reason' => $reason,

                'reference' => $reference

            ]

        );

    }

    /**
     * ---------------------------------------------------------
     * Verify Transfer
     * ---------------------------------------------------------
     */
    public function verifyTransfer(
        string $transferCode
    ): array {

        return $this->request(

            'GET',

            '/transfer/'.$transferCode

        );

    }

    /**
     * ---------------------------------------------------------
     * Finalize OTP Transfer
     * ---------------------------------------------------------
     */
    public function finalizeTransfer(
        string $transferCode,
        string $otp
    ): array {

        return $this->request(

            'POST',

            '/transfer/finalize_transfer',

            [

                'transfer_code' => $transferCode,

                'otp' => $otp

            ]

        );

    }

    /**
     * ---------------------------------------------------------
     * Resolve Bank Account
     * ---------------------------------------------------------
     */
    public function resolveAccount(
        string $accountNumber,
        string $bankCode
    ): array {

        return $this->request(

            'GET',

            '/bank/resolve?account_number='
            .urlencode($accountNumber)
            .'&bank_code='
            .urlencode($bankCode)

        );

    }

    /**
     * ---------------------------------------------------------
     * List Banks
     * ---------------------------------------------------------
     */
    public function banks(): array
    {

        return $this->request(

            'GET',

            '/bank?country=nigeria'

        );

    }

}