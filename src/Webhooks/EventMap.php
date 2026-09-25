<?php

declare(strict_types=1);

namespace DPay\Laravel\Webhooks;

use DPay\Laravel\Events\CheckoutCancelled;
use DPay\Laravel\Events\CheckoutCompleted;
use DPay\Laravel\Events\CheckoutExpired;
use DPay\Laravel\Events\PaymentExpired;
use DPay\Laravel\Events\PaymentFailed;
use DPay\Laravel\Events\PaymentPaid;
use DPay\Laravel\Events\PaymentRefunded;
use DPay\Laravel\Events\PaymentVoided;
use DPay\Laravel\Events\WebhookEvent;
use DPay\Laravel\Events\WebhookReceived;
use DPay\Laravel\Events\WebhookTestReceived;
use DPay\Webhooks\Event;

/** DPay event name → the typed Laravel event. Unknown names get only {@see WebhookReceived}. */
final class EventMap
{
    /** @var array<string, class-string<WebhookEvent>> */
    public const MAP = [
        'payment.paid' => PaymentPaid::class,
        'payment.failed' => PaymentFailed::class,
        'payment.expired' => PaymentExpired::class,
        'payment.refunded' => PaymentRefunded::class,
        'payment.voided' => PaymentVoided::class,
        'checkout.completed' => CheckoutCompleted::class,
        'checkout.expired' => CheckoutExpired::class,
        'checkout.cancelled' => CheckoutCancelled::class,
        'webhook.test' => WebhookTestReceived::class,
    ];

    public static function typed(Event $event): ?WebhookEvent
    {
        $class = self::MAP[$event->name] ?? null;

        return $class === null ? null : new $class($event);
    }
}
