<?php

declare(strict_types=1);

namespace DPay\Laravel;

use DPay\Models\CheckoutSession;

/** What one reconcile run saw. */
final class ReconcileReport
{
    /** @var list<CheckoutSession> */
    public array $checkouts = [];

    /** @var array<string, int> status → count */
    public array $statuses = [];

    /** @var list<string> ids the API does not know (other environment, deleted) */
    public array $notFound = [];

    /** @var array<string, string> id → API error message */
    public array $errors = [];

    /** Ids beyond the per-run read cap — they wait for the next run. */
    public int $skipped = 0;

    public function read(): int
    {
        return count($this->checkouts);
    }

    public function count(string $status): int
    {
        return $this->statuses[$status] ?? 0;
    }
}
