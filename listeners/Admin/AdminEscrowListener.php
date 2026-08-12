<?php

declare(strict_types=1);

namespace Listeners\Admin;

use Core\Database;
use Core\Logger;
use PDO;
use Throwable;

class AdminEscrowListener
{
    /**
     * ---------------------------------------------------------
     * CONFIGURATION
     * ---------------------------------------------------------
     */

    private const ROUTE = '/admin/escrow_payout';

    private const SESSION_KEY = 'sendam_admin_authenticated';

    private const ESCROW_AWAITING_PAYOUT = 'awaiting_payout';

    private const ESCROW_COMPLETED = 'completed';


    /**
     * ---------------------------------------------------------
     * HANDLE REQUEST
     * ---------------------------------------------------------
     */

    public function handle(): void
    {
        try {

            /*
             * Start the session before ANY output.
             */
            $this->startSession();


            $this->log(
                'listener_start',
                [
                    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                    'uri'    => $_SERVER['REQUEST_URI'] ?? '',
                    'get'    => $_GET,
                    'post'   => $this->safePost(),
                    'ip'     => $_SERVER['REMOTE_ADDR'] ?? ''
                ]
            );


            /*
             * -------------------------------------------------
             * LOGIN
             * -------------------------------------------------
             */

            if (
                ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
                &&
                ($_POST['action'] ?? '') === 'login'
            ) {

                $this->handleLogin();

                return;
            }


            /*
             * -------------------------------------------------
             * LOGOUT
             * -------------------------------------------------
             */

            if (
                ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
                &&
                ($_POST['action'] ?? '') === 'logout'
            ) {

                $this->handleLogout();

                return;
            }


            /*
             * -------------------------------------------------
             * AUTHENTICATION
             * -------------------------------------------------
             */

            if (!$this->isAuthenticated()) {

                $this->renderLogin();

                return;
            }


            /*
             * -------------------------------------------------
             * PROCESS PAYOUT
             * -------------------------------------------------
             */

            if (
                ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
                &&
                ($_POST['action'] ?? '') === 'mark_paid'
            ) {

                $this->processPayout();

                return;
            }


            /*
             * -------------------------------------------------
             * SINGLE ESCROW
             * -------------------------------------------------
             */

            if (
                isset($_GET['id'])
                &&
                (int)$_GET['id'] > 0
            ) {

                $this->renderEscrow(
                    (int)$_GET['id']
                );

                return;
            }


            /*
             * -------------------------------------------------
             * ESCROW LIST
             * -------------------------------------------------
             */

            $this->renderEscrowList();

        } catch (Throwable $e) {

            $this->log(
                'listener_exception',
                [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString()
                ]
            );


            http_response_code(500);

            $this->renderFatalError(
                'Unable to load the Escrow Administration page.'
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * SESSION
     * ---------------------------------------------------------
     */

    private function startSession(): void
    {
        if (
            session_status()
            === PHP_SESSION_ACTIVE
        ) {

            return;
        }


        if (
            headers_sent(
                $file,
                $line
            )
        ) {

            $this->log(
                'session_headers_already_sent',
                [
                    'file' => $file,
                    'line' => $line
                ]
            );

            return;
        }


        session_start();
    }


    /**
     * ---------------------------------------------------------
     * LOGIN
     * ---------------------------------------------------------
     */

    private function handleLogin(): void
    {
        $username =
            trim(
                (string)(
                    $_POST['username']
                    ?? ''
                )
            );


        $password =
            (string)(
                $_POST['password']
                ?? ''
            );


        $this->log(
            'admin_login_attempt',
            [
                'username' =>
                    $username
                    !== ''
                        ? $username
                        : '[empty]'
            ]
        );


        if (
            !defined(
                'ADMIN_ESCROW_USERNAME'
            )
            ||
            !defined(
                'ADMIN_ESCROW_PASSWORD'
            )
        ) {

            $this->log(
                'admin_login_config_missing'
            );


            $this->renderLogin(
                'Admin login configuration is missing.'
            );

            return;
        }


        $validUsername =
            hash_equals(
                (string)ADMIN_ESCROW_USERNAME,
                $username
            );


        $validPassword =
            hash_equals(
                (string)ADMIN_ESCROW_PASSWORD,
                $password
            );


        if (
            !$validUsername
            ||
            !$validPassword
        ) {

            $this->log(
                'admin_login_failed',
                [
                    'username' => $username
                ]
            );


            $this->renderLogin(
                'Invalid username or password.'
            );

            return;
        }


        /*
         * Regenerate session ID after login.
         */

        if (
            session_status()
            === PHP_SESSION_ACTIVE
        ) {

            session_regenerate_id(
                true
            );
        }


        $_SESSION[
            self::SESSION_KEY
        ] = true;


        $_SESSION[
            'sendam_admin_login_time'
        ] = time();


        /*
         * Create CSRF token.
         */

        $_SESSION[
            'sendam_admin_csrf'
        ] =
            bin2hex(
                random_bytes(32)
            );


        $this->log(
            'admin_login_success',
            [
                'username' =>
                    $username
            ]
        );


        $this->redirect(
            self::ROUTE
        );
    }


    /**
     * ---------------------------------------------------------
     * LOGOUT
     * ---------------------------------------------------------
     */

    private function handleLogout(): void
    {
        $this->log(
            'admin_logout'
        );


        $_SESSION = [];


        if (
            ini_get(
                'session.use_cookies'
            )
        ) {

            $params =
                session_get_cookie_params();


            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool)$params['secure'],
                (bool)$params['httponly']
            );
        }


        if (
            session_status()
            === PHP_SESSION_ACTIVE
        ) {

            session_destroy();
        }


        $this->redirect(
            self::ROUTE
        );
    }


    /**
     * ---------------------------------------------------------
     * AUTHENTICATION
     * ---------------------------------------------------------
     */

    private function isAuthenticated(): bool
    {
        $this->startSession();


        return
            isset(
                $_SESSION[
                    self::SESSION_KEY
                ]
            )
            &&
            $_SESSION[
                self::SESSION_KEY
            ] === true;
    }


    /**
     * ---------------------------------------------------------
     * CSRF TOKEN
     * ---------------------------------------------------------
     */

    private function csrfToken(): string
    {
        $this->startSession();


        if (
            empty(
                $_SESSION[
                    'sendam_admin_csrf'
                ]
            )
        ) {

            $_SESSION[
                'sendam_admin_csrf'
            ] =
                bin2hex(
                    random_bytes(32)
                );
        }


        return (string)(
            $_SESSION[
                'sendam_admin_csrf'
            ]
        );
    }


    /**
     * ---------------------------------------------------------
     * VERIFY CSRF
     * ---------------------------------------------------------
     */

    private function verifyCsrf(): bool
    {
        $this->startSession();


        $sessionToken =
            (string)(
                $_SESSION[
                    'sendam_admin_csrf'
                ]
                ?? ''
            );


        $submittedToken =
            (string)(
                $_POST[
                    'csrf_token'
                ]
                ?? ''
            );


        if (
            $sessionToken === ''
            ||
            $submittedToken === ''
        ) {

            return false;
        }


        return hash_equals(
            $sessionToken,
            $submittedToken
        );
    }


    /**
     * ---------------------------------------------------------
     * PROCESS PAYOUT
     * ---------------------------------------------------------
     */

    private function processPayout(): void
    {
        $this->log(
            'payout_process_started',
            [
                'post' =>
                    $this->safePost()
            ]
        );


        /*
         * -------------------------------------------------
         * CSRF
         * -------------------------------------------------
         */

        if (
            !$this->verifyCsrf()
        ) {

            $this->log(
                'payout_csrf_failed'
            );


            $this->redirect(
                self::ROUTE
                . '?error='
                . rawurlencode(
                    'Security token expired. Please try again.'
                )
            );
        }


        /*
         * -------------------------------------------------
         * ESCROW ID
         * -------------------------------------------------
         */

        $escrowId =
            (int)(
                $_POST[
                    'escrow_id'
                ]
                ?? 0
            );


        if (
            $escrowId <= 0
        ) {

            $this->redirect(
                self::ROUTE
                . '?error='
                . rawurlencode(
                    'Invalid escrow ID.'
                )
            );
        }


        /*
         * -------------------------------------------------
         * PAYOUT REFERENCE
         * -------------------------------------------------
         */

        $payoutReference =
            trim(
                (string)(
                    $_POST[
                        'payout_reference'
                    ]
                    ?? ''
                )
            );


        if (
            $payoutReference === ''
        ) {

            $this->redirect(
                self::ROUTE
                . '?id='
                . $escrowId
                . '&error='
                . rawurlencode(
                    'Payout reference is required.'
                )
            );
        }


        if (
            mb_strlen(
                $payoutReference
            ) > 255
        ) {

            $this->redirect(
                self::ROUTE
                . '?id='
                . $escrowId
                . '&error='
                . rawurlencode(
                    'Payout reference is too long.'
                )
            );
        }


        try {

            $db =
                Database::getInstance()
                    ->connection();


            /*
             * -------------------------------------------------
             * TRANSACTION
             * -------------------------------------------------
             */

            $db->beginTransaction();


            /*
             * Lock the escrow row.
             *
             * This prevents two admin requests from
             * processing the same escrow simultaneously.
             */

            $stmt =
                $db->prepare("
                    SELECT *
                    FROM escrows
                    WHERE id = ?
                    LIMIT 1
                    FOR UPDATE
                ");


            $stmt->execute([
                $escrowId
            ]);


            $escrow =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$escrow) {

                $db->rollBack();


                $this->log(
                    'payout_escrow_not_found',
                    [
                        'escrow_id' =>
                            $escrowId
                    ]
                );


                $this->redirect(
                    self::ROUTE
                    . '?error='
                    . rawurlencode(
                        'Escrow not found.'
                    )
                );
            }


            /*
             * -------------------------------------------------
             * CHECK STATUS
             * -------------------------------------------------
             */

            $status =
                (string)(
                    $escrow[
                        'status'
                    ]
                    ?? ''
                );


            if (
                $status
                === self::ESCROW_COMPLETED
            ) {

                $db->rollBack();


                $this->redirect(
                    self::ROUTE
                    . '?id='
                    . $escrowId
                    . '&error='
                    . rawurlencode(
                        'This escrow has already been completed.'
                    )
                );
            }


            if (
                $status
                !== self::ESCROW_AWAITING_PAYOUT
            ) {

                $db->rollBack();


                $this->log(
                    'payout_invalid_status',
                    [
                        'escrow_id' =>
                            $escrowId,
                        'status' =>
                            $status
                    ]
                );


                $this->redirect(
                    self::ROUTE
                    . '?id='
                    . $escrowId
                    . '&error='
                    . rawurlencode(
                        'This escrow is not awaiting payout.'
                    )
                );
            }


            /*
             * -------------------------------------------------
             * CHECK SELLER BANK ACCOUNT
             * -------------------------------------------------
             */

            $sellerId =
                (int)(
                    $escrow[
                        'seller_id'
                    ]
                    ?? 0
                );


            if (
                $sellerId <= 0
            ) {

                $db->rollBack();


                $this->redirect(
                    self::ROUTE
                    . '?id='
                    . $escrowId
                    . '&error='
                    . rawurlencode(
                        'Seller ID is missing from this escrow.'
                    )
                );
            }


            $bankStmt =
                $db->prepare("
                    SELECT
                        id,
                        seller_id,
                        account_name,
                        account_number,
                        bank_code,
                        bank_name,
                        recipient_code,
                        currency,
                        status,
                        verified_at,
                        created_at,
                        updated_at
                    FROM escrow_wallets
                    WHERE seller_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");


            $bankStmt->execute([
                $sellerId
            ]);


            $bank =
                $bankStmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$bank) {

                $db->rollBack();


                $this->log(
                    'payout_bank_account_missing',
                    [
                        'escrow_id' =>
                            $escrowId,
                        'seller_id' =>
                            $sellerId
                    ]
                );


                $this->redirect(
                    self::ROUTE
                    . '?id='
                    . $escrowId
                    . '&error='
                    . rawurlencode(
                        'Seller bank account was not found.'
                    )
                );
            }


            /*
             * -------------------------------------------------
             * CHECK BANK STATUS
             * -------------------------------------------------
             *
             * We do NOT require "verified" here because your
             * current escrow_wallets example has:
             *
             * status = pending
             *
             * and still contains a valid recipient_code.
             *
             * The admin can therefore review the account
             * details before manually transferring.
             */


            $accountName =
                trim(
                    (string)(
                        $bank[
                            'account_name'
                        ]
                        ?? ''
                    )
                );


            $accountNumber =
                trim(
                    (string)(
                        $bank[
                            'account_number'
                        ]
                        ?? ''
                    )
                );


            $bankName =
                trim(
                    (string)(
                        $bank[
                            'bank_name'
                        ]
                        ?? ''
                    )
                );


            if (
                $accountName === ''
                ||
                $accountNumber === ''
                ||
                $bankName === ''
            ) {

                $db->rollBack();


                $this->redirect(
                    self::ROUTE
                    . '?id='
                    . $escrowId
                    . '&error='
                    . rawurlencode(
                        'Seller bank details are incomplete.'
                    )
                );
            }


            /*
             * -------------------------------------------------
             * PAYOUT AMOUNT
             * -------------------------------------------------
             */

            $sellerAmount =
                (float)(
                    $escrow[
                        'seller_amount'
                    ]
                    ?? 0
                );


            if (
                $sellerAmount <= 0
            ) {

                $db->rollBack();


                $this->redirect(
                    self::ROUTE
                    . '?id='
                    . $escrowId
                    . '&error='
                    . rawurlencode(
                        'Invalid seller payout amount.'
                    )
                );
            }


            /*
             * -------------------------------------------------
             * UPDATE ESCROW
             * -------------------------------------------------
             *
             * IMPORTANT:
             *
             * This records that the admin has manually
             * transferred the seller's money.
             *
             * It does NOT call Paystack or another bank API.
             */

            $update =
                $db->prepare("
                    UPDATE escrows
                    SET
                        status = ?,
                        payout_reference = ?,
                        released_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                    AND status = ?
                ");


            $update->execute([
                self::ESCROW_COMPLETED,
                $payoutReference,
                $escrowId,
                self::ESCROW_AWAITING_PAYOUT
            ]);


            if (
                $update->rowCount()
                !== 1
            ) {

                $db->rollBack();


                $this->log(
                    'payout_update_failed',
                    [
                        'escrow_id' =>
                            $escrowId
                    ]
                );


                $this->redirect(
                    self::ROUTE
                    . '?id='
                    . $escrowId
                    . '&error='
                    . rawurlencode(
                        'Unable to mark escrow as paid.'
                    )
                );
            }


            /*
             * -------------------------------------------------
             * COMMIT
             * -------------------------------------------------
             */

            $db->commit();


            $this->log(
                'payout_completed',
                [
                    'escrow_id' =>
                        $escrowId,

                    'escrow_reference' =>
                        $escrow[
                            'reference'
                        ]
                        ?? null,

                    'seller_id' =>
                        $sellerId,

                    'seller_amount' =>
                        $sellerAmount,

                    'currency' =>
                        $escrow[
                            'currency'
                        ]
                        ?? 'NGN',

                    'payout_reference' =>
                        $payoutReference,

                    'bank_name' =>
                        $bankName,

                    'account_number' =>
                        $this->maskAccountNumber(
                            $accountNumber
                        )
                ]
            );


            /*
             * -------------------------------------------------
             * REDIRECT
             * -------------------------------------------------
             */

            $this->redirect(
                self::ROUTE
                . '?id='
                . $escrowId
                . '&success='
                . rawurlencode(
                    'Payout recorded successfully. Escrow marked as completed.'
                )
            );

        } catch (Throwable $e) {

            if (
                isset($db)
                &&
                $db instanceof PDO
                &&
                $db->inTransaction()
            ) {

                $db->rollBack();
            }


            $this->log(
                'payout_exception',
                [
                    'escrow_id' =>
                        $escrowId,
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


            $this->redirect(
                self::ROUTE
                . '?id='
                . $escrowId
                . '&error='
                . rawurlencode(
                    'Payout processing failed. Check the admin escrow log.'
                )
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * ESCROW LIST
     * ---------------------------------------------------------
     */

    private function renderEscrowList(): void
    {
        try {

            $db =
                Database::getInstance()
                    ->connection();


            $stmt =
                $db->query("
                    SELECT
                        e.*
                    FROM escrows e
                    ORDER BY e.id DESC
                ");


            $escrows =
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                );


        } catch (Throwable $e) {

            $this->log(
                'escrow_list_error',
                [
                    'message' =>
                        $e->getMessage(),
                    'file' =>
                        $e->getFile(),
                    'line' =>
                        $e->getLine()
                ]
            );


            $this->renderError(
                'Unable to load escrows.'
            );

            return;
        }


        $this->pageStart(
            'Escrow Administration'
        );

        ?>

        <div class="topbar">

            <div>

                <h1>
                    🛡️ Escrow Administration
                </h1>

                <p>
                    Manage escrow transactions and seller payouts.
                </p>

            </div>


            <form
                method="POST"
                action="<?= $this->e(self::ROUTE) ?>"
            >

                <input
                    type="hidden"
                    name="action"
                    value="logout"
                >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= $this->e(
                        $this->csrfToken()
                    ) ?>"
                >

                <button
                    type="submit"
                    class="logout"
                >
                    Logout
                </button>

            </form>

        </div>


        <?php $this->renderMessages(); ?>


        <div class="stats">

            <div class="stat">

                <span>
                    Total Escrows
                </span>

                <strong>
                    <?= count($escrows) ?>
                </strong>

            </div>


            <div class="stat warning-stat">

                <span>
                    Awaiting Payout
                </span>

                <strong>
                    <?= $this->countStatus(
                        $escrows,
                        self::ESCROW_AWAITING_PAYOUT
                    ) ?>
                </strong>

            </div>


            <div class="stat">

                <span>
                    Completed
                </span>

                <strong>
                    <?= $this->countStatus(
                        $escrows,
                        self::ESCROW_COMPLETED
                    ) ?>
                </strong>

            </div>


            <div class="stat">

                <span>
                    Cancelled
                </span>

                <strong>
                    <?= $this->countStatus(
                        $escrows,
                        'cancelled'
                    ) ?>
                </strong>

            </div>

        </div>


        <div class="card">

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>

                            <th>ID</th>
                            <th>Escrow</th>
                            <th>Listing</th>
                            <th>Buyer</th>
                            <th>Seller</th>
                            <th>Amount</th>
                            <th>Fee</th>
                            <th>Seller Gets</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th></th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php if (!$escrows): ?>

                        <tr>

                            <td
                                colspan="11"
                                class="empty"
                            >
                                No escrow records found.
                            </td>

                        </tr>

                    <?php endif; ?>


                    <?php foreach ($escrows as $escrow): ?>

                        <?php

                        $status =
                            (string)(
                                $escrow[
                                    'status'
                                ]
                                ?? ''
                            );

                        ?>

                        <tr>

                            <td>
                                <?= (int)(
                                    $escrow[
                                        'id'
                                    ]
                                    ?? 0
                                ) ?>
                            </td>


                            <td>

                                <strong>
                                    <?= $this->e(
                                        $escrow[
                                            'reference'
                                        ]
                                        ?? '-'
                                    ) ?>
                                </strong>

                            </td>


                            <td>

                                #
                                <?= (int)(
                                    $escrow[
                                        'listing_id'
                                    ]
                                    ?? 0
                                ) ?>

                            </td>


                            <td>

                                <?= $this->e(
                                    $escrow[
                                        'buyer_phone'
                                    ]
                                    ??
                                    $escrow[
                                        'buyer_id'
                                    ]
                                    ??
                                    '-'
                                ) ?>

                            </td>


                            <td>

                                <?= $this->e(
                                    $escrow[
                                        'seller_phone'
                                    ]
                                    ??
                                    $escrow[
                                        'seller_id'
                                    ]
                                    ??
                                    '-'
                                ) ?>

                            </td>


                            <td>

                                <?= $this->money(
                                    $escrow[
                                        'amount'
                                    ]
                                    ?? 0,
                                    $escrow[
                                        'currency'
                                    ]
                                    ?? 'NGN'
                                ) ?>

                            </td>


                            <td>

                                <?= $this->money(
                                    $escrow[
                                        'escrow_fee'
                                    ]
                                    ?? 0,
                                    $escrow[
                                        'currency'
                                    ]
                                    ?? 'NGN'
                                ) ?>

                            </td>


                            <td>

                                <strong>

                                    <?= $this->money(
                                        $escrow[
                                            'seller_amount'
                                        ]
                                        ?? 0,
                                        $escrow[
                                            'currency'
                                        ]
                                        ?? 'NGN'
                                    ) ?>

                                </strong>

                            </td>


                            <td>

                                <span class="status status-<?= $this->e(
                                    str_replace(
                                        '_',
                                        '-',
                                        $status
                                    )
                                ) ?>">

                                    <?= $this->e(
                                        strtoupper(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $status
                                            )
                                        )
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <?= $this->e(
                                    $escrow[
                                        'created_at'
                                    ]
                                    ?? '-'
                                ) ?>

                            </td>


                            <td>

                                <a
                                    class="view-button"
                                    href="<?= $this->e(
                                        self::ROUTE
                                    ) ?>?id=<?= (int)(
                                        $escrow[
                                            'id'
                                        ]
                                        ?? 0
                                    ) ?>"
                                >

                                    <?= $status === self::ESCROW_AWAITING_PAYOUT
                                        ? 'Process Payout'
                                        : 'View' ?>

                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <?php

        $this->pageEnd();
    }


    /**
     * ---------------------------------------------------------
     * SINGLE ESCROW
     * ---------------------------------------------------------
     */

    private function renderEscrow(
        int $id
    ): void {

        try {

            $db =
                Database::getInstance()
                    ->connection();


            /*
             * -------------------------------------------------
             * ESCROW
             * -------------------------------------------------
             */

            $stmt =
                $db->prepare("
                    SELECT *
                    FROM escrows
                    WHERE id = ?
                    LIMIT 1
                ");


            $stmt->execute([
                $id
            ]);


            $escrow =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$escrow) {

                http_response_code(404);

                $this->renderError(
                    'Escrow not found.'
                );

                return;
            }


            /*
             * -------------------------------------------------
             * SELLER BANK DETAILS
             * -------------------------------------------------
             */

            $bank = null;


            $sellerId =
                (int)(
                    $escrow[
                        'seller_id'
                    ]
                    ?? 0
                );


            if (
                $sellerId > 0
            ) {

                $bankStmt =
                    $db->prepare("
                        SELECT
                            id,
                            seller_id,
                            account_name,
                            account_number,
                            bank_code,
                            bank_name,
                            recipient_code,
                            currency,
                            status,
                            verified_at,
                            created_at,
                            updated_at
                        FROM escrow_wallets
                        WHERE seller_id = ?
                        ORDER BY id DESC
                        LIMIT 1
                    ");


                $bankStmt->execute([
                    $sellerId
                ]);


                $bank =
                    $bankStmt->fetch(
                        PDO::FETCH_ASSOC
                    )
                    ?: null;
            }


        } catch (Throwable $e) {

            $this->log(
                'single_escrow_error',
                [
                    'escrow_id' =>
                        $id,
                    'message' =>
                        $e->getMessage(),
                    'file' =>
                        $e->getFile(),
                    'line' =>
                        $e->getLine()
                ]
            );


            http_response_code(500);

            $this->renderError(
                'Unable to load escrow details.'
            );

            return;
        }


        $this->pageStart(
            'Escrow '
            . (
                $escrow[
                    'reference'
                ]
                ?? ''
            )
        );

        ?>


        <div class="topbar">

            <div>

                <h1>
                    🛡️ Escrow Details
                </h1>

                <p>

                    <?= $this->e(
                        $escrow[
                            'reference'
                        ]
                        ?? '-'
                    ) ?>

                </p>

            </div>


            <a
                class="logout"
                href="<?= $this->e(
                    self::ROUTE
                ) ?>"
            >
                ← Back
            </a>

        </div>


        <?php $this->renderMessages(); ?>


        <div class="grid">


            <!-- TRANSACTION -->

            <div class="card">

                <h2>
                    📋 Transaction
                </h2>


                <?= $this->detail(
                    'Escrow ID',
                    $escrow['id'] ?? null
                ) ?>


                <?= $this->detail(
                    'Escrow Reference',
                    $escrow['reference'] ?? null
                ) ?>


                <?= $this->detail(
                    'Listing ID',
                    $escrow['listing_id'] ?? null
                ) ?>


                <?= $this->detail(
                    'Status',
                    strtoupper(
                        str_replace(
                            '_',
                            ' ',
                            (string)(
                                $escrow['status']
                                ?? ''
                            )
                        )
                    )
                ) ?>


                <?= $this->detail(
                    'Currency',
                    $escrow['currency'] ?? null
                ) ?>


                <?= $this->detail(
                    'Payment Method',
                    $escrow['payment_method'] ?? null
                ) ?>


                <?= $this->detail(
                    'Payment Reference',
                    $escrow['payment_reference'] ?? null
                ) ?>


                <?= $this->detail(
                    'Payout Reference',
                    $escrow['payout_reference'] ?? null
                ) ?>


                <?= $this->detail(
                    'Created',
                    $escrow['created_at'] ?? null
                ) ?>


                <?= $this->detail(
                    'Updated',
                    $escrow['updated_at'] ?? null
                ) ?>


                <?= $this->detail(
                    'Released',
                    $escrow['released_at'] ?? null
                ) ?>

            </div>


            <!-- MONEY -->

            <div class="card">

                <h2>
                    💰 Money
                </h2>


                <?= $this->moneyDetail(
                    'Buyer Paid',
                    $escrow['amount'] ?? 0,
                    $escrow['currency'] ?? 'NGN'
                ) ?>


                <?= $this->moneyDetail(
                    'Escrow Fee',
                    $escrow['escrow_fee'] ?? 0,
                    $escrow['currency'] ?? 'NGN'
                ) ?>


                <?= $this->moneyDetail(
                    'Seller Payout',
                    $escrow['seller_amount'] ?? 0,
                    $escrow['currency'] ?? 'NGN'
                ) ?>


                <div class="payout-total">

                    <span>
                        SELLER PAYOUT
                    </span>

                    <strong>

                        <?= $this->money(
                            $escrow[
                                'seller_amount'
                            ]
                            ?? 0,
                            $escrow[
                                'currency'
                            ]
                            ?? 'NGN'
                        ) ?>

                    </strong>

                </div>

            </div>


            <!-- BUYER -->

            <div class="card">

                <h2>
                    👤 Buyer
                </h2>


                <?= $this->detail(
                    'Buyer ID',
                    $escrow['buyer_id'] ?? null
                ) ?>


                <?= $this->detail(
                    'Phone',
                    $escrow['buyer_phone'] ?? null
                ) ?>


                <?= $this->detail(
                    'Email',
                    $escrow['buyer_email'] ?? null
                ) ?>


                <?= $this->detail(
                    'Confirmed At',
                    $escrow['buyer_confirmed_at'] ?? null
                ) ?>

            </div>


            <!-- SELLER -->

            <div class="card">

                <h2>
                    👤 Seller
                </h2>


                <?= $this->detail(
                    'Seller ID',
                    $escrow['seller_id'] ?? null
                ) ?>


                <?= $this->detail(
                    'Phone',
                    $escrow['seller_phone'] ?? null
                ) ?>


                <?= $this->detail(
                    'Email',
                    $escrow['seller_email'] ?? null
                ) ?>


                <?= $this->detail(
                    'Confirmed At',
                    $escrow['seller_confirmed_at'] ?? null
                ) ?>

            </div>


            <!-- SELLER BANK -->

            <div class="card bank-card">

                <h2>
                    🏦 Seller Bank Account
                </h2>


                <?php if ($bank): ?>


                    <?= $this->detail(
                        'Account Name',
                        $bank['account_name'] ?? null
                    ) ?>


                    <?= $this->detail(
                        'Account Number',
                        $bank['account_number'] ?? null
                    ) ?>


                    <?= $this->detail(
                        'Bank Name',
                        $bank['bank_name'] ?? null
                    ) ?>


                    <?= $this->detail(
                        'Bank Code',
                        $bank['bank_code'] ?? null
                    ) ?>


                    <?= $this->detail(
                        'Recipient Code',
                        $bank['recipient_code'] ?? null
                    ) ?>


                    <?= $this->detail(
                        'Currency',
                        $bank['currency'] ?? null
                    ) ?>


                    <?= $this->detail(
                        'Account Status',
                        $bank['status'] ?? null
                    ) ?>


                    <?= $this->detail(
                        'Verified At',
                        $bank['verified_at'] ?? null
                    ) ?>


                    <?php if (
                        !empty(
                            $bank[
                                'recipient_code'
                            ]
                        )
                    ): ?>

                        <div class="recipient-box">

                            <strong>
                                Paystack Recipient
                            </strong>

                            <code>
                                <?= $this->e(
                                    $bank[
                                        'recipient_code'
                                    ]
                                ) ?>
                            </code>

                        </div>

                    <?php endif; ?>


                <?php else: ?>


                    <div class="alert error">

                        Seller bank account not found.

                    </div>


                <?php endif; ?>

            </div>


            <!-- PAYOUT -->

            <div class="card payout-card">

                <h2>
                    💸 Seller Payout
                </h2>


                <?php if (
                    ($escrow['status'] ?? '')
                    === self::ESCROW_AWAITING_PAYOUT
                ): ?>


                    <div class="warning">

                        <strong>
                            ⚠️ PAYOUT REQUIRED
                        </strong>

                        <p>

                            The buyer has confirmed receipt.
                            Review the seller's bank details
                            and transfer the amount below.

                        </p>

                    </div>


                    <div class="pay-amount">

                        <?= $this->money(
                            $escrow[
                                'seller_amount'
                            ]
                            ?? 0,
                            $escrow[
                                'currency'
                            ]
                            ?? 'NGN'
                        ) ?>

                    </div>


                    <?php if ($bank): ?>

                        <div class="transfer-box">

                            <h3>
                                Transfer To
                            </h3>


                            <div class="transfer-account">

                                <strong>

                                    <?= $this->e(
                                        $bank[
                                            'account_name'
                                        ]
                                    ) ?>

                                </strong>


                                <span>

                                    <?= $this->e(
                                        $bank[
                                            'account_number'
                                        ]
                                    ) ?>

                                </span>


                                <span>

                                    <?= $this->e(
                                        $bank[
                                            'bank_name'
                                        ]
                                    ) ?>

                                </span>

                            </div>


                            <?php if (
                                !empty(
                                    $bank[
                                        'recipient_code'
                                    ]
                                )
                            ): ?>

                                <div class="recipient-code">

                                    Recipient:

                                    <strong>

                                        <?= $this->e(
                                            $bank[
                                                'recipient_code'
                                            ]
                                        ) ?>

                                    </strong>

                                </div>

                            <?php endif; ?>

                        </div>


                        <form
                            method="POST"
                            action="<?= $this->e(
                                self::ROUTE
                            ) ?>"
                            onsubmit="return confirmPayout();"
                        >

                            <input
                                type="hidden"
                                name="action"
                                value="mark_paid"
                            >


                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= $this->e(
                                    $this->csrfToken()
                                ) ?>"
                            >


                            <input
                                type="hidden"
                                name="escrow_id"
                                value="<?= (int)(
                                    $escrow['id']
                                ) ?>"
                            >


                            <label>
                                Payout Reference
                            </label>


                            <input
                                type="text"
                                name="payout_reference"
                                maxlength="255"
                                placeholder="e.g. TRF-123456789"
                                required
                            >


                            <button
                                type="submit"
                                class="process-button"
                            >
                                ✅ Mark Seller Paid
                            </button>

                        </form>


                    <?php else: ?>


                        <div class="alert error">

                            Cannot process payout because
                            seller bank details are missing.

                        </div>

                    <?php endif; ?>


                <?php elseif (
                    ($escrow['status'] ?? '')
                    === self::ESCROW_COMPLETED
                ): ?>


                    <div class="paid">

                        <span>
                            ✅
                        </span>

                        <strong>
                            SELLER PAID
                        </strong>

                    </div>


                    <?= $this->detail(
                        'Payout Reference',
                        $escrow[
                            'payout_reference'
                        ]
                        ?? null
                    ) ?>


                    <?= $this->detail(
                        'Released At',
                        $escrow[
                            'released_at'
                        ]
                        ?? null
                    ) ?>


                <?php else: ?>


                    <div class="info">

                        Current status:

                        <strong>

                            <?= $this->e(
                                strtoupper(
                                    str_replace(
                                        '_',
                                        ' ',
                                        (string)(
                                            $escrow[
                                                'status'
                                            ]
                                            ?? '-'
                                        )
                                    )
                                )
                            ) ?>

                        </strong>

                    </div>


                <?php endif; ?>

            </div>


        </div>


        <?php

        $this->pageEnd();
    }


    /**
     * ---------------------------------------------------------
     * MESSAGES
     * ---------------------------------------------------------
     */

    private function renderMessages(): void
    {
        if (
            !empty(
                $_GET['success']
            )
        ) {

            ?>

            <div class="alert success">

                <?= $this->e(
                    (string)(
                        $_GET['success']
                    )
                ) ?>

            </div>

            <?php
        }


        if (
            !empty(
                $_GET['error']
            )
        ) {

            ?>

            <div class="alert error">

                <?= $this->e(
                    (string)(
                        $_GET['error']
                    )
                ) ?>

            </div>

            <?php
        }
    }


    /**
     * ---------------------------------------------------------
     * LOGIN PAGE
     * ---------------------------------------------------------
     */

    private function renderLogin(
        ?string $error = null
    ): void {

        $this->pageStart(
            'Admin Login'
        );

        ?>


        <div class="login-wrapper">

            <div class="login-card">

                <div class="logo">
                    🛡️
                </div>


                <h1>
                    SENDAM Escrow
                </h1>


                <p>
                    Administrator Portal
                </p>


                <?php if ($error): ?>

                    <div class="alert error">

                        <?= $this->e(
                            $error
                        ) ?>

                    </div>

                <?php endif; ?>


                <form
                    method="POST"
                    action="<?= $this->e(
                        self::ROUTE
                    ) ?>"
                >

                    <input
                        type="hidden"
                        name="action"
                        value="login"
                    >


                    <label>
                        Username
                    </label>


                    <input
                        type="text"
                        name="username"
                        autocomplete="username"
                        required
                    >


                    <label>
                        Password
                    </label>


                    <input
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                    >


                    <button
                        type="submit"
                    >
                        Login
                    </button>

                </form>

            </div>

        </div>


        <?php

        $this->pageEnd();
    }


    /**
     * ---------------------------------------------------------
     * COUNT STATUS
     * ---------------------------------------------------------
     */

    private function countStatus(
        array $escrows,
        string $status
    ): int {

        $count = 0;


        foreach (
            $escrows
            as $escrow
        ) {

            if (
                ($escrow['status'] ?? '')
                === $status
            ) {

                $count++;
            }
        }


        return $count;
    }


    /**
     * ---------------------------------------------------------
     * DETAIL
     * ---------------------------------------------------------
     */

    private function detail(
        string $label,
        mixed $value
    ): string {

        if (
            $value === null
            ||
            $value === ''
        ) {

            $value = '—';
        }


        return
            '<div class="detail">'
            .
            '<span>'
            .
            $this->e(
                $label
            )
            .
            '</span>'
            .
            '<strong>'
            .
            $this->e(
                $value
            )
            .
            '</strong>'
            .
            '</div>';
    }


    /**
     * ---------------------------------------------------------
     * MONEY DETAIL
     * ---------------------------------------------------------
     */

    private function moneyDetail(
        string $label,
        mixed $value,
        string $currency = 'NGN'
    ): string {

        return
            '<div class="detail">'
            .
            '<span>'
            .
            $this->e(
                $label
            )
            .
            '</span>'
            .
            '<strong>'
            .
            $this->money(
                $value,
                $currency
            )
            .
            '</strong>'
            .
            '</div>';
    }


    /**
     * ---------------------------------------------------------
     * MONEY
     * ---------------------------------------------------------
     */

    private function money(
        mixed $amount,
        string $currency = 'NGN'
    ): string {

        $currency =
            strtoupper(
                trim(
                    $currency
                )
            );


        if (
            $currency === 'NGN'
        ) {

            return
                '₦'
                .
                number_format(
                    (float)$amount,
                    2
                );
        }


        return
            $this->e(
                $currency
            )
            .
            ' '
            .
            number_format(
                (float)$amount,
                2
            );
    }


    /**
     * ---------------------------------------------------------
     * MASK ACCOUNT NUMBER
     * ---------------------------------------------------------
     */

    private function maskAccountNumber(
        string $accountNumber
    ): string {

        $length =
            strlen(
                $accountNumber
            );


        if (
            $length <= 4
        ) {

            return $accountNumber;
        }


        return
            str_repeat(
                '*',
                $length - 4
            )
            .
            substr(
                $accountNumber,
                -4
            );
    }


    /**
     * ---------------------------------------------------------
     * ESCAPE
     * ---------------------------------------------------------
     */

    private function e(
        mixed $value
    ): string {

        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }


    /**
     * ---------------------------------------------------------
     * REDIRECT
     * ---------------------------------------------------------
     */

    private function redirect(
        string $url
    ): never {

        header(
            'Location: '
            . $url
        );

        exit;
    }


    /**
     * ---------------------------------------------------------
     * ERROR
     * ---------------------------------------------------------
     */

    private function renderError(
        string $message
    ): void {

        $this->pageStart(
            'Escrow Error'
        );

        ?>

        <div class="login-wrapper">

            <div class="login-card">

                <div class="logo">
                    ⚠️
                </div>


                <h1>
                    Escrow Error
                </h1>


                <div class="alert error">

                    <?= $this->e(
                        $message
                    ) ?>

                </div>


                <a
                    href="<?= $this->e(
                        self::ROUTE
                    ) ?>"
                    class="view-button"
                >
                    Return to Escrow Admin
                </a>

            </div>

        </div>

        <?php

        $this->pageEnd();
    }


    /**
     * ---------------------------------------------------------
     * FATAL ERROR
     * ---------------------------------------------------------
     */

    private function renderFatalError(
        string $message
    ): void {

        $this->pageStart(
            'Escrow Error'
        );

        ?>

        <div class="login-wrapper">

            <div class="login-card">

                <div class="logo">
                    ⚠️
                </div>


                <h1>
                    Escrow Administration
                </h1>


                <div class="alert error">

                    <?= $this->e(
                        $message
                    ) ?>

                </div>

            </div>

        </div>

        <?php

        $this->pageEnd();
    }


    /**
     * ---------------------------------------------------------
     * PAGE START
     * ---------------------------------------------------------
     */

    private function pageStart(
        string $title
    ): void {

        ?>

        <!DOCTYPE html>

        <html lang="en">

        <head>

            <meta charset="UTF-8">


            <meta
                name="viewport"
                content="width=device-width, initial-scale=1.0"
            >


            <title>
                <?= $this->e(
                    $title
                ) ?>
            </title>


            <style>

                * {
                    box-sizing: border-box;
                }


                body {
                    margin: 0;
                    background: #f5f7fb;
                    color: #172033;
                    font-family:
                        Arial,
                        Helvetica,
                        sans-serif;
                }


                a {
                    text-decoration: none;
                }


                .container {
                    max-width: 1450px;
                    margin: auto;
                    padding: 30px;
                }


                .topbar {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 20px;
                    margin-bottom: 25px;
                }


                .topbar h1 {
                    margin: 0 0 7px;
                }


                .topbar p {
                    margin: 0;
                    color: #687386;
                }


                .logout {
                    background: #172033;
                    color: white;
                    padding: 11px 17px;
                    border-radius: 8px;
                    border: 0;
                    cursor: pointer;
                    font-weight: bold;
                }


                .card {
                    background: white;
                    border-radius: 14px;
                    padding: 22px;
                    margin-bottom: 22px;
                    box-shadow:
                        0 4px 20px
                        rgba(0,0,0,.05);
                }


                .card h2 {
                    margin-top: 0;
                    margin-bottom: 20px;
                }


                .stats {
                    display: grid;
                    grid-template-columns:
                        repeat(4, 1fr);
                    gap: 16px;
                    margin-bottom: 22px;
                }


                .stat {
                    background: white;
                    border-radius: 14px;
                    padding: 20px;
                    box-shadow:
                        0 4px 20px
                        rgba(0,0,0,.05);
                }


                .stat span {
                    display: block;
                    color: #687386;
                    font-size: 13px;
                    margin-bottom: 8px;
                }


                .stat strong {
                    font-size: 28px;
                }


                .warning-stat {
                    background: #fffaf0;
                    border: 1px solid #f0d48a;
                }


                .table-wrapper {
                    overflow-x: auto;
                }


                table {
                    width: 100%;
                    border-collapse: collapse;
                    min-width: 1100px;
                }


                th,
                td {
                    padding: 14px 12px;
                    border-bottom:
                        1px solid #edf0f4;
                    text-align: left;
                    white-space: nowrap;
                }


                th {
                    font-size: 12px;
                    text-transform: uppercase;
                    color: #6b7280;
                }


                .view-button {
                    display: inline-block;
                    background: #172033;
                    color: white;
                    padding: 8px 13px;
                    border-radius: 7px;
                    font-weight: bold;
                }


                .status {
                    display: inline-block;
                    padding: 6px 9px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: bold;
                }


                .status-awaiting-payout {
                    background: #fff3cd;
                    color: #856404;
                }


                .status-completed {
                    background: #d1fae5;
                    color: #065f46;
                }


                .status-cancelled {
                    background: #fee2e2;
                    color: #991b1b;
                }


                .status-pending {
                    background: #f3f4f6;
                    color: #374151;
                }


                .empty {
                    text-align: center;
                    padding: 40px;
                    color: #6b7280;
                }


                .alert {
                    padding: 14px 17px;
                    border-radius: 9px;
                    margin-bottom: 20px;
                }


                .alert.success {
                    background: #d1fae5;
                    color: #065f46;
                }


                .alert.error {
                    background: #fee2e2;
                    color: #991b1b;
                }


                .grid {
                    display: grid;
                    grid-template-columns:
                        repeat(
                            2,
                            minmax(0, 1fr)
                        );
                    gap: 22px;
                }


                .detail {
                    display: flex;
                    justify-content: space-between;
                    gap: 20px;
                    padding: 12px 0;
                    border-bottom:
                        1px solid #edf0f4;
                }


                .detail span {
                    color: #6b7280;
                }


                .detail strong {
                    text-align: right;
                    overflow-wrap: anywhere;
                }


                .payout-total {
                    margin-top: 20px;
                    padding: 18px;
                    background: #172033;
                    color: white;
                    border-radius: 10px;
                    display: flex;
                    justify-content: space-between;
                    gap: 20px;
                }


                .payout-total strong {
                    font-size: 20px;
                }


                .payout-card {
                    grid-column: 1 / -1;
                }


                .bank-card {
                    grid-column: 1 / -1;
                }


                .warning {
                    padding: 16px;
                    background: #fff3cd;
                    color: #664d03;
                    border-radius: 9px;
                    margin-bottom: 20px;
                }


                .warning p {
                    margin-bottom: 0;
                }


                .pay-amount {
                    font-size: 34px;
                    font-weight: bold;
                    margin: 20px 0;
                }


                .transfer-box {
                    background: #f8fafc;
                    border: 1px solid #dce3ed;
                    border-radius: 12px;
                    padding: 20px;
                    margin-bottom: 20px;
                }


                .transfer-box h3 {
                    margin-top: 0;
                }


                .transfer-account {
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                    font-size: 17px;
                }


                .transfer-account span {
                    color: #475569;
                }


                .recipient-code {
                    margin-top: 15px;
                    padding-top: 15px;
                    border-top:
                        1px solid #e2e8f0;
                    color: #64748b;
                }


                .recipient-code strong {
                    color: #172033;
                }


                .recipient-box {
                    margin-top: 18px;
                    padding: 15px;
                    background: #f8fafc;
                    border-radius: 9px;
                }


                .recipient-box strong {
                    display: block;
                    margin-bottom: 7px;
                }


                .recipient-box code {
                    word-break: break-all;
                }


                label {
                    display: block;
                    font-weight: bold;
                    margin: 14px 0 7px;
                }


                input {
                    width: 100%;
                    padding: 13px;
                    border:
                        1px solid #d8dee8;
                    border-radius: 8px;
                    font-size: 15px;
                }


                button[type="submit"] {
                    margin-top: 18px;
                    padding: 14px 20px;
                    border: 0;
                    border-radius: 8px;
                    background: #172033;
                    color: white;
                    font-size: 15px;
                    font-weight: bold;
                    cursor: pointer;
                }


                .process-button {
                    width: 100%;
                    font-size: 16px !important;
                    padding: 16px !important;
                }


                .paid {
                    padding: 22px;
                    background: #d1fae5;
                    color: #065f46;
                    border-radius: 10px;
                    display: flex;
                    gap: 12px;
                    font-size: 18px;
                    margin-bottom: 20px;
                }


                .info {
                    padding: 18px;
                    background: #eef2ff;
                    color: #3730a3;
                    border-radius: 9px;
                }


                .login-wrapper {
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                }


                .login-card {
                    width: 100%;
                    max-width: 420px;
                    background: white;
                    padding: 35px;
                    border-radius: 16px;
                    box-shadow:
                        0 10px 40px
                        rgba(0,0,0,.08);
                }


                .login-card h1 {
                    margin-bottom: 5px;
                }


                .login-card p {
                    color: #6b7280;
                    margin-top: 0;
                    margin-bottom: 25px;
                }


                .logo {
                    font-size: 42px;
                    margin-bottom: 10px;
                }


                @media (max-width: 900px) {

                    .stats {
                        grid-template-columns:
                            repeat(2, 1fr);
                    }


                    .grid {
                        grid-template-columns: 1fr;
                    }


                    .payout-card,
                    .bank-card {
                        grid-column: auto;
                    }

                }


                @media (max-width: 600px) {

                    .container {
                        padding: 15px;
                    }


                    .stats {
                        grid-template-columns: 1fr;
                    }


                    .topbar {
                        align-items: flex-start;
                        flex-direction: column;
                    }


                    .detail {
                        flex-direction: column;
                        gap: 5px;
                    }


                    .detail strong {
                        text-align: left;
                    }


                    .pay-amount {
                        font-size: 28px;
                    }

                }

            </style>

        </head>


        <body>

        <?php if (
            $this->isAuthenticated()
        ): ?>

            <main class="container">

        <?php endif; ?>

        <?php
    }


    /**
     * ---------------------------------------------------------
     * PAGE END
     * ---------------------------------------------------------
     */

    private function pageEnd(): void
    {
        ?>

            <?php if (
                $this->isAuthenticated()
            ): ?>

                </main>

            <?php endif; ?>


            <script>

                function confirmPayout()
                {
                    return confirm(
                        'IMPORTANT: Confirm that you have manually transferred the exact seller payout to the bank account shown on this page. This action will mark the escrow as COMPLETED.'
                    );
                }

            </script>


        </body>

        </html>

        <?php
    }


    /**
     * ---------------------------------------------------------
     * LOGGING
     * ---------------------------------------------------------
     */

    private function log(
        string $event,
        array $data = []
    ): void {

        try {

            Logger::write(
                'admin_escrow',
                array_merge(
                    [
                        'event' =>
                            $event,

                        'time' =>
                            date(
                                'Y-m-d H:i:s'
                            )
                    ],
                    $data
                )
            );

        } catch (Throwable $e) {

            error_log(
                'ADMIN ESCROW LOGGER ERROR: '
                . $e->getMessage()
            );
        }
    }


    /**
     * ---------------------------------------------------------
     * SAFE POST
     * ---------------------------------------------------------
     */

    private function safePost(): array
    {
        $post =
            $_POST;


        if (
            isset(
                $post['password']
            )
        ) {

            $post['password'] =
                '[REDACTED]';
        }


        if (
            isset(
                $post['csrf_token']
            )
        ) {

            $post['csrf_token'] =
                '[REDACTED]';
        }


        return $post;
    }
}