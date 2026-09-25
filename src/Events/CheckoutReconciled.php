<?php

declare(strict_types=1);

namespace DPay\Laravel\Events;

use DPay\Laravel\Reconciler;
use DPay\Models\CheckoutSession;

/**
 * A checkout re-read by `dpay:reconcile` / {@see Reconciler}
 * (no transition callable given). Run the same idempotent order transition
 * your CheckoutCompleted listener runs — `$checkout->status` is the truth.
 */
final class CheckoutReconciled
{
    public function __construct(public readonly CheckoutSession $checkout) {}
}
