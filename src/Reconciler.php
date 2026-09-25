<?php

declare(strict_types=1);

namespace DPay\Laravel;

use DPay\Exceptions\DPayException;
use DPay\Exceptions\NotFoundException;
use DPay\Laravel\Events\CheckoutReconciled;
use DPay\Models\CheckoutSession;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The 5-minute reconcile: re-read every pending checkout and hand it to the
 * SAME idempotent transition the return handler and the webhook listener
 * run. For stores DPay cannot reach (HTTP-only or private hosts — common in
 * Libya) this is the settlement path, not a fallback.
 *
 *   DPay::reconciler()->run(Order::pendingCheckoutIds(), function (CheckoutSession $c) { … });
 *
 * Without a callable, every re-read checkout is dispatched as a
 * {@see CheckoutReconciled} event. Reads are capped at `dpay.reconcile.limit`
 * (30) per run to stay inside the 120/min read throttle.
 */
final class Reconciler
{
    public function __construct(
        private readonly DPayManager $manager,
        private ?Dispatcher $events = null,
    ) {}

    /**
     * @param  iterable<string>  $ids  checkout session ids (cs_… / cs_test_…)
     * @param  (callable(CheckoutSession): void)|null  $transition  your idempotent order transition
     */
    public function run(iterable $ids, ?callable $transition = null, ?int $limit = null): ReconcileReport
    {
        if ($limit === null) {
            $configured = $this->manager->config()['reconcile'] ?? null;
            $limit = is_array($configured) && is_numeric($configured['limit'] ?? null) ? (int) $configured['limit'] : 30;
        }
        $limit = $limit > 0 ? $limit : 30;
        $report = new ReconcileReport();
        $seen = 0;
        foreach ($ids as $id) {
            if ($seen >= $limit) {
                $report->skipped++;
                continue;
            }
            $seen++;
            try {
                $checkout = $this->manager->checkoutSessions()->get($id);
            } catch (NotFoundException) {
                $report->notFound[] = $id;
                continue;
            } catch (DPayException $e) {
                $report->errors[$id] = $e->getMessage();
                continue;
            }
            $report->statuses[$checkout->status->value] = ($report->statuses[$checkout->status->value] ?? 0) + 1;
            $report->checkouts[] = $checkout;
            if ($transition !== null) {
                $transition($checkout);
            } else {
                $this->events()->dispatch(new CheckoutReconciled($checkout));
            }
        }

        return $report;
    }

    private function events(): Dispatcher
    {
        return $this->events ??= Container::getInstance()->make(Dispatcher::class);
    }
}
