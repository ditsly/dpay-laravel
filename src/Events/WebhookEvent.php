<?php

declare(strict_types=1);

namespace DPay\Laravel\Events;

use DPay\Money\Money;
use DPay\Webhooks\Event;

/**
 * Base of every event the webhook route dispatches. `$event` is the verified
 * SDK payload; the accessors below are the fields a listener reaches for.
 *
 * Every listener must be idempotent by order state: the package dedupes on
 * (live, id, event), but a reconcile run or a return-page read can
 * legitimately settle the same order first.
 */
abstract class WebhookEvent
{
    public function __construct(public readonly Event $event) {}

    public function name(): string
    {
        return $this->event->name;
    }

    /** `live` flag — false for sandbox events; null on webhook.test. */
    public function live(): ?bool
    {
        return $this->event->live();
    }

    /** `checkout_session_id` (checkout.*) or `data.checkout_session_id` (a checkout attempt's payment.*). */
    public function checkoutSessionId(): ?string
    {
        return $this->event->checkoutSessionId();
    }

    public function sessionId(): ?int
    {
        return $this->event->sessionId();
    }

    /** The store's order number (`reference`, or `data.reference` on an attempt's payment.*), when the checkout carried one. */
    public function reference(): ?string
    {
        return $this->event->reference();
    }

    public function amount(): ?Money
    {
        return $this->event->amount();
    }

    public function status(): ?string
    {
        return $this->event->status();
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->event->payload;
    }

    /** @return array<string, mixed> `metadata` (checkout.*) or `data.metadata` (payment.*) */
    public function metadata(): array
    {
        return $this->event->metadata();
    }

    public function dedupeKey(): string
    {
        return $this->event->dedupeKey();
    }
}
