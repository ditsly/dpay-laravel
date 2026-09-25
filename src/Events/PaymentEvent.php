<?php

declare(strict_types=1);

namespace DPay\Laravel\Events;

/**
 * A `payment.*` event: one terminal event per payment session (attempt).
 * For hosted checkout these are informational per attempt —
 * `checkoutSessionId()` names the checkout; act on the checkout.* events.
 */
abstract class PaymentEvent extends WebhookEvent
{
    public function payMethod(): ?string
    {
        return $this->event->payMethod();
    }

    public function txId(): ?string
    {
        return $this->event->txId();
    }

    /** @return array<string, mixed> your `data` keys plus the server's (original_amount, fee_percent, fee_amount, …) */
    public function data(): array
    {
        return $this->event->data();
    }

    public function occurredAt(): ?\DateTimeImmutable
    {
        return $this->event->occurredAt();
    }
}
