<?php

declare(strict_types=1);

namespace DPay\Laravel\Events;

use DPay\Money\Money;

/** A `checkout.*` event: the checkout-level truth for hosted checkout integrations. */
abstract class CheckoutEvent extends WebhookEvent
{
    public function currency(): string
    {
        return $this->event->currency() ?? 'LYD';
    }

    /**
     * The winning attempt on checkout.completed — `session_id`, `pay_method`,
     * `tx_id`, `amount_charged` (2dp, fee-inclusive), `fee_amount`, `paid_at`,
     * `receipt_url`; null on expired/cancelled.
     *
     * @return array<string, mixed>|null
     */
    public function payment(): ?array
    {
        return $this->event->payment();
    }

    public function txId(): ?string
    {
        $v = $this->payment()['tx_id'] ?? null;

        return is_string($v) ? $v : null;
    }

    public function payMethod(): ?string
    {
        $v = $this->payment()['pay_method'] ?? null;

        return is_string($v) ? $v : null;
    }

    /** What the payer's instrument was actually debited (fee-inclusive, 2dp). */
    public function amountCharged(): ?Money
    {
        return Money::fromApi($this->payment()['amount_charged'] ?? null);
    }

    public function receiptUrl(): ?string
    {
        $v = $this->payment()['receipt_url'] ?? null;

        return is_string($v) ? $v : null;
    }

    /**
     * The guard every transition runs before marking an order paid: amount
     * (at 2dp), currency and reference must equal the order's.
     */
    public function matchesOrder(Money|string|int|float $amount, string $currency = 'LYD', ?string $reference = null): bool
    {
        $expected = $amount instanceof Money ? $amount : Money::of($amount);
        $actual = $this->amount();
        if ($actual === null || ! $actual->equalsAt($expected, 2)) {
            return false;
        }
        if (strtoupper($this->currency()) !== strtoupper($currency)) {
            return false;
        }

        return $reference === null || $this->reference() === $reference;
    }
}
