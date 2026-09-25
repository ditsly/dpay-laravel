<?php

declare(strict_types=1);

namespace DPay\Laravel\Examples\Shop;

use DPay\Laravel\Events\CheckoutCompleted;
use DPay\Models\CheckoutSession;
use DPay\Models\CheckoutStatus;

/**
 * THE one transition. Called from the return page (with a re-read
 * CheckoutSession), from the CheckoutCompleted listener (with the webhook
 * event) and from the reconciler — idempotent by order state, guarded by
 * amount/currency/reference equality, never completing on a mismatch.
 */
final class OrderTransition
{
    public function apply(Order $order, CheckoutSession $checkout): void
    {
        if (! $checkout->matchesOrder($order->total, 'LYD', (string) $order->id)) {
            $order->flagForReview('DPay amount/currency/reference mismatch for '.$checkout->id);

            return;
        }
        if ($checkout->status === CheckoutStatus::Paid && ! $order->paid) {
            $order->markPaid(
                $checkout->payment?->txId,
                $checkout->payment?->amountCharged->format(2),   // the 2 dp fee-inclusive debit — never recompute fees
                $checkout->payment?->receiptUrl,
            );
        } elseif ($checkout->status === CheckoutStatus::Expired) {
            $order->freeForRetry();                               // your pending-order policy decides what happens next
        }
    }

    public function applyWebhook(Order $order, CheckoutCompleted $event): void
    {
        if (! $event->matchesOrder($order->total, 'LYD', (string) $order->id)) {
            $order->flagForReview('DPay webhook mismatch for '.($event->checkoutSessionId() ?? '?'));

            return;
        }
        if (! $order->paid) {
            $order->markPaid($event->txId(), $event->amountCharged()?->format(2), $event->receiptUrl());
        }
    }
}
