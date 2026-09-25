<?php

declare(strict_types=1);

namespace DPay\Laravel\Tests\Unit;

use DPay\Laravel\Events\CheckoutCancelled;
use DPay\Laravel\Events\CheckoutCompleted;
use DPay\Laravel\Events\CheckoutExpired;
use DPay\Laravel\Events\PaymentExpired;
use DPay\Laravel\Events\PaymentFailed;
use DPay\Laravel\Events\PaymentPaid;
use DPay\Laravel\Events\PaymentRefunded;
use DPay\Laravel\Events\PaymentVoided;
use DPay\Laravel\Events\WebhookTestReceived;
use DPay\Laravel\Webhooks\CacheDeduper;
use DPay\Laravel\Webhooks\EventMap;
use DPay\Laravel\Webhooks\NullDeduper;
use DPay\Webhooks\Event;
use DPay\Webhooks\WebhookEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    #[Test]
    public function everyCatalogueEventHasATypedClassAndUnknownNamesHaveNone(): void
    {
        $expected = [
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
        self::assertSame($expected, EventMap::MAP);
        foreach (WebhookEvent::cases() as $case) {
            self::assertArrayHasKey($case->value, EventMap::MAP, 'the SDK catalogue and the Laravel map must agree');
            $typed = EventMap::typed(Event::fromRawBody('{"event":"'.$case->value.'","live":true}'));
            self::assertInstanceOf($expected[$case->value], $typed);
            self::assertSame($case->value, $typed->name());
        }
        self::assertNull(EventMap::typed(Event::fromRawBody('{"event":"invoice.created"}')));
    }

    #[Test]
    public function checkoutEventAccessorsReadTheA34Payload(): void
    {
        $raw = '{"event":"checkout.expired","live":true,"checkout_session_id":"cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2","reference":"10483","status":"expired","amount":125.5,"currency":"LYD","description":"Order #10483","metadata":{"order_id":10483},"customer":null,"payment":null,"created_at":"2026-09-21T10:00:00+00:00","occurred_at":"2026-09-21T11:00:00+00:00"}';
        $e = new CheckoutExpired(Event::fromRawBody($raw, '1758452400'));
        self::assertSame('cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', $e->checkoutSessionId());
        self::assertNull($e->sessionId());
        self::assertSame('expired', $e->status());
        self::assertNull($e->payment());
        self::assertNull($e->txId());
        self::assertNull($e->amountCharged());
        self::assertNull($e->receiptUrl());
        self::assertSame(['order_id' => 10483], $e->metadata());
        self::assertTrue($e->live());
        self::assertSame('live:cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2:checkout.expired', $e->dedupeKey());
        self::assertTrue($e->matchesOrder(125.5));
        self::assertTrue($e->matchesOrder('125.50', 'lyd', '10483'));
    }

    #[Test]
    public function paymentEventAccessorsReadTheLegacyPayloadAndItsCheckoutLink(): void
    {
        $raw = '{"event":"payment.failed","live":false,"session_id":15,"status":"failed","amount":26.14,"pay_method":"edfali","tx_id":null,"system_reference":null,"network_reference":null,"paid_through":null,"payer_account":"0912****","data":{"order_id":77,"fee_percent":1,"fee_amount":0.259,"original_amount":25.88,"checkout_session_id":"cs_test_01K5N2M3Q4R5S6T7V8W9X0Y1Z2","metadata":{"order_id":77}},"created_at":"2026-09-21T10:00:00+00:00","paid_at":"2026-09-21T10:03:00+00:00"}';
        $e = new PaymentFailed(Event::fromRawBody($raw));
        self::assertSame(15, $e->sessionId());
        self::assertSame('cs_test_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', $e->checkoutSessionId());
        self::assertSame('26.14', (string) $e->amount());
        self::assertSame('edfali', $e->payMethod());
        self::assertNull($e->txId());
        self::assertSame(77, $e->data()['order_id']);
        self::assertSame(['order_id' => 77], $e->metadata());
        self::assertNull($e->reference());
        self::assertSame('2026-09-21T10:03:00+00:00', $e->occurredAt()?->format(DATE_ATOM));
        self::assertSame('sandbox:15:payment.failed', $e->dedupeKey());
    }

    #[Test]
    public function theCacheKeyIsStableAndTheNullDeduperAlwaysClaims(): void
    {
        self::assertSame(CacheDeduper::cacheKey('live:1:payment.paid'), CacheDeduper::cacheKey('live:1:payment.paid'));
        self::assertStringStartsWith('dpay:webhook:', CacheDeduper::cacheKey('x'));
        $null = new NullDeduper();
        self::assertTrue($null->claim('k', 'e'));
        self::assertTrue($null->claim('k', 'e'));
    }
}
