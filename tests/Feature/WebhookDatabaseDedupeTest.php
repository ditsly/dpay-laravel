<?php

declare(strict_types=1);

namespace DPay\Laravel\Tests\Feature;

use DPay\Laravel\Events\CheckoutCompleted;
use DPay\Laravel\Tests\TestCase;
use DPay\Laravel\Webhooks\DatabaseDeduper;
use DPay\Laravel\Webhooks\Deduper;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

/** `dpay.webhook.dedupe = database`: the published migration's unique index is the claim. */
final class WebhookDatabaseDedupeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $migration = require __DIR__.'/../../database/migrations/create_dpay_webhook_events_table.php.stub';
        $migration->up();
    }

    #[Test]
    #[DefineEnvironment('useTheDatabaseDeduper')]
    public function theTableMakesTheClaimAtomicAndTheDuplicateANoOp(): void
    {
        self::assertInstanceOf(DatabaseDeduper::class, $this->app->make(Deduper::class));
        Event::fake([CheckoutCompleted::class]);

        $this->postWebhook(self::checkoutCompletedBody(false))->assertOk()->assertJsonPath('duplicate', false);
        $this->postWebhook(self::checkoutCompletedBody(false))->assertOk()->assertJsonPath('duplicate', true);

        Event::assertDispatched(CheckoutCompleted::class, 1);
        $rows = DB::table('dpay_webhook_events')->get();
        self::assertCount(1, $rows);
        self::assertSame('sandbox:cs_test_01K5N2M3Q4R5S6T7V8W9X0Y1Z2:checkout.completed', $rows[0]->dedupe_key);
        self::assertSame('checkout.completed', $rows[0]->event);
    }

    #[Test]
    #[DefineEnvironment('useTheDatabaseDeduper')]
    public function aFailedListenerReleasesTheRow(): void
    {
        Event::listen(CheckoutCompleted::class, static function (): void {
            throw new \RuntimeException('boom');
        });
        $this->postWebhook(self::checkoutCompletedBody(false))->assertStatus(500);
        self::assertSame(0, DB::table('dpay_webhook_events')->count());
    }

    #[Test]
    public function theDeduperItselfClaimsOnce(): void
    {
        $deduper = new DatabaseDeduper(DB::connection(), 'dpay_webhook_events');
        self::assertTrue($deduper->claim('live:1:payment.paid', 'payment.paid'));
        self::assertFalse($deduper->claim('live:1:payment.paid', 'payment.paid'));
        self::assertTrue($deduper->claim('live:1:payment.refunded', 'payment.refunded'));
        $deduper->release('live:1:payment.paid');
        self::assertTrue($deduper->claim('live:1:payment.paid', 'payment.paid'));
    }

    /** @param Application $app */
    protected function useTheDatabaseDeduper($app): void
    {
        $app['config']->set('dpay.webhook.dedupe', 'database');
    }
}
