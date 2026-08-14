<?php

declare(strict_types=1);

namespace Services\Payments;

use Services\Escrow\PaystackEscrowPaymentService as CanonicalPaystackEscrowPaymentService;

/**
 * Backwards-compatible proxy.
 *
 * Escrow payment business logic lives only in
 * Services\Escrow\PaystackEscrowPaymentService.
 */
class PaystackEscrowPaymentService extends CanonicalPaystackEscrowPaymentService
{
}
