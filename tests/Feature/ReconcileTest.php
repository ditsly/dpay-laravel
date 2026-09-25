<?php

declare(strict_types=1);

namespace DPay\Laravel\Tests\Feature;

use DPay\Laravel\Events\CheckoutReconciled;
use DPay\Laravel\Facades\DPay;
use DPay\Laravel\Tests\TestCase;
use DPay\Models\CheckoutSession;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

final class ReconcileTest extends TestCase
{
    #[Test]
    public function theReconcilerReReadsEachCheckoutAndRunsTheTransition(): void
    {
        $fake = DPay::fake();
        $paid = $fake->seedCheckout('125.50', '1');
        $fake->markPaid($paid);
        $open = $fake->seedCheckout('20.00', '2');
        $expired = $fake->seedCheckout('30.00', '3');
        $fake->markExpired($expired);

        $seen = [];
        $report = DPay::reconciler()->run([$paid, $open, $expired, 'cs_test_00000000000000000000000000'], static function (CheckoutSession $c) use (&$seen): void {
            $seen[$c->id] = $c->status->value;
        });

        self::assertSame([$paid => 'paid', $open => 'open', $expired => 'expired'], $seen);
        self::assertSame(3, $report->read());
        self::assertSame(1, $report->count('paid'));
        self::assertSame(['cs_test_00000000000000000000000000'], $report->notFound);
        self::assertSame([], $report->errors);
        self::assertSame(0, $report->skipped);
        $fake->assertCheckoutRead($paid)->assertCheckoutRead($open)->assertCheckoutRead($expired);
    }

    #[Test]
    public function withoutACallableItDispatchesCheckoutReconciledAndCapsReadsPerRun(): void
    {
        $fake = DPay::fake();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $fake->seedCheckout('10.00', (string) $i);
        }
        Event::fake([CheckoutReconciled::class]);

        $report = DPay::reconciler()->run($ids, null, 3);

        self::assertSame(3, $report->read());
        self::assertSame(2, $report->skipped);
        Event::assertDispatched(CheckoutReconciled::class, 3);
        self::assertCount(3, $fake->requests());
    }

    #[Test]
    public function apiErrorsAreCollectedNotThrown(): void
    {
        $fake = DPay::fake();
        $id = $fake->seedCheckout('10.00', '1');
        $fake->respondWith('GET', '#/api/v2/checkout-sessions/'.$id.'$#', 503, ['type' => '/api/v2/problems/service-unavailable', 'title' => 'Service Unavailable', 'status' => 503, 'detail' => 'Upstream down.'], [], false);
        $report = DPay::reconciler()->run([$id], static fn () => null);
        self::assertSame([$id => 'Upstream down.'], $report->errors);
        self::assertSame(0, $report->read());
    }

    #[Test]
    public function theArtisanCommandPrintsABilingualReport(): void
    {
        $fake = DPay::fake();
        $paid = $fake->seedCheckout('125.50', '10483');
        $fake->markPaid($paid);
        Event::fake([CheckoutReconciled::class]);

        $this->artisan('dpay:reconcile', ['ids' => [$paid, 'cs_test_00000000000000000000000000']])
            ->expectsOutputToContain('تمت قراءة 1 جلسة: paid=1')
            ->expectsOutputToContain('Read 1 checkout(s): paid=1')
            ->expectsOutputToContain($paid.'  paid       125.50 LYD  ref=10483')
            ->expectsOutputToContain('Not found (other environment or unknown): cs_test_00000000000000000000000000')
            ->assertExitCode(0);
        Event::assertDispatched(CheckoutReconciled::class, static fn (CheckoutReconciled $e): bool => $e->checkout->id === $paid && $e->checkout->isPaid());
    }

    #[Test]
    public function theCommandRefusesToRunWithoutIds(): void
    {
        DPay::fake();
        $this->artisan('dpay:reconcile')
            ->expectsOutputToContain('No checkout ids given')
            ->assertExitCode(2);
    }
}
