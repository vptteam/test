<?php

declare(strict_types=1);

namespace Modules\Escrow\Models;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;

class EscrowBankAccount
{
    protected string $table = 'escrow_bank_accounts';

    /**
     * Create Bank Account
     */
    public function create(array $data): ?array
    {
        try {

            $existing = $this->findByUser(
                (int)$data['user_id']
            );

            if ($existing) {

                $this->update(
                    (int)$existing['id'],
                    $data
                );

                return $this->find(
                    (int)$existing['id']
                );

            }

            $db = Database::connect();

            $stmt = $db->prepare("
                INSERT INTO {$this->table}
                (
                    user_id,
                    bank_code,
                    bank_name,
                    account_number,
                    account_name,
                    recipient_code,
                    is_default,
                    verified_at,
                    created_at,
                    updated_at
                )

                VALUES
                (
                    :user_id,
                    :bank_code,
                    :bank_name,
                    :account_number,
                    :account_name,
                    :recipient_code,
                    :is_default,
                    :verified_at,
                    NOW(),
                    NOW()
                )
            ");

            $stmt->execute([

                'user_id'         => $data['user_id'],

                'bank_code'       => $data['bank_code'],

                'bank_name'       => $data['bank_name'],

                'account_number'  => $data['account_number'],

                'account_name'    => $data['account_name'],

                'recipient_code'  => $data['recipient_code'] ?? null,

                'is_default'      => $data['is_default'] ?? 1,

                'verified_at'     => $data['verified_at'] ?? date('Y-m-d H:i:s')

            ]);

            Logger::write(
                'escrow_bank_account',
                [
                    'step' => 'CREATE',
                    'user_id' => $data['user_id']
                ]
            );

            return $this->find(
                (int)$db->lastInsertId()
            );

        } catch (Throwable $e) {

            Logger::write(
                'escrow_bank_account_error',
                [
                    'step' => 'CREATE',
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]
            );

            return null;

        }
    }

    /**
     * Find By ID
     */
    public function find(int $id): ?array
    {
        try {

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE id=?
                LIMIT 1
            ");

            $stmt->execute([$id]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_bank_account_error',
                [
                    'step' => 'FIND',
                    'message' => $e->getMessage()
                ]
            );

            return null;

        }
    }

    /**
     * Find User Bank Account
     */
    public function findByUser(
        int $userId
    ): ?array
    {
        try {

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE user_id=?
                LIMIT 1
            ");

            $stmt->execute([
                $userId
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_bank_account_error',
                [
                    'step' => 'FIND_BY_USER',
                    'message' => $e->getMessage()
                ]
            );

            return null;

        }
    }

    /**
     * Find By Recipient Code
     */
    public function findByRecipient(
        string $recipientCode
    ): ?array
    {
        try {

            $db = Database::connect();

            $stmt = $db->prepare("
                SELECT *
                FROM {$this->table}
                WHERE recipient_code=?
                LIMIT 1
            ");

            $stmt->execute([
                $recipientCode
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;

        } catch (Throwable $e) {

            Logger::write(
                'escrow_bank_account_error',
                [
                    'step' => 'FIND_RECIPIENT',
                    'message' => $e->getMessage()
                ]
            );

            return null;

        }
    }

    /**
     * Update Bank Account
     */
    public function update(
        int $id,
        array $data
    ): bool
    {
        try {

            if (empty($data)) {
                return false;
            }

            $db = Database::connect();

            $fields = [];

            foreach ($data as $column => $value) {

                $fields[] = "{$column}=:{$column}";

            }

            $fields[] = "updated_at=NOW()";

            $sql = "
                UPDATE {$this->table}
                SET ".implode(',', $fields)."
                WHERE id=:id
            ";

            $stmt = $db->prepare($sql);

            $data['id'] = $id;

            return $stmt->execute($data);

        } catch (Throwable $e) {

            Logger::write(
                'escrow_bank_account_error',
                [
                    'step' => 'UPDATE',
                    'message' => $e->getMessage()
                ]
            );

            return false;

        }
    }

    /**
     * Delete Bank Account
     */
    public function delete(
        int $id
    ): bool
    {
        try {

            $db = Database::connect();

            $stmt = $db->prepare("
                DELETE
                FROM {$this->table}
                WHERE id=?
            ");

            return $stmt->execute([
                $id
            ]);

        } catch (Throwable $e) {

            Logger::write(
                'escrow_bank_account_error',
                [
                    'step' => 'DELETE',
                    'message' => $e->getMessage()
                ]
            );

            return false;

        }
    }

    /**
     * List All Bank Accounts
     */
    public function all(): array
    {
        try {

            $db = Database::connect();

            return $db
                ->query("
                    SELECT *
                    FROM {$this->table}
                    ORDER BY id DESC
                ")
                ->fetchAll(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            Logger::write(
                'escrow_bank_account_error',
                [
                    'step' => 'ALL',
                    'message' => $e->getMessage()
                ]
            );

            return [];

        }
    }
}