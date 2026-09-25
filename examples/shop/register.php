<?php

declare(strict_types=1);

/**
 * Wiring — in AppServiceProvider::boot() (Laravel 11+) or EventServiceProvider,
 * and routes/console.php for the reconciler.
 */

use DPay\Laravel\Events\CheckoutCompleted;
use DPay\Laravel\Events\CheckoutExpired;
use DPay\Laravel\Examples\Shop\Order;
use DPay\Laravel\Examples\Shop\OrderRepository;
use DPay\Laravel\Examples\Shop\OrderTransition;
use DPay\Laravel\Facades\DPay;
use DPay\Models\CheckoutSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;

// Your lookup — by the checkout id stored at create time, never by metadata alone.
$findOrder = static fn (?string $checkoutId): ?Order => OrderRepository::byCheckoutId($checkoutId);

// Step 4 — the webhook. Verification, dedupe and the 200 are the package's; the listener only transitions.
Event::listen(CheckoutCompleted::class, static function (CheckoutCompleted $event) use ($findOrder): void {
    $order = $findOrder($event->checkoutSessionId());
    if ($order !== null) {
        app(OrderTransition::class)->applyWebhook($order, $event);
    }
});
Event::listen(CheckoutExpired::class, static function (CheckoutExpired $event) use ($findOrder): void {
    $findOrder($event->checkoutSessionId())?->freeForRetry();
});

// Step 5 — reconcile every five minutes: the settlement path for stores DPay cannot reach.
Schedule::call(static function () use ($findOrder): void {
    /** @var list<string> $pending ids of orders pending ≤ 24 h with a checkout id */
    $pending = [];
    DPay::reconciler()->run($pending, static function (CheckoutSession $checkout) use ($findOrder): void {
        $order = $findOrder($checkout->id);
        if ($order !== null) {
            app(OrderTransition::class)->apply($order, $checkout);
        }
    });
})->everyFiveMinutes();
