<?php

declare(strict_types=1);

namespace DPay\Laravel\Console;

use DPay\Laravel\DPayManager;
use DPay\Laravel\Events\CheckoutReconciled;
use Illuminate\Console\Command;
use Illuminate\Contracts\Translation\Translator;

/**
 * `php artisan dpay:reconcile cs_… cs_… [--limit=30]` (or `--stdin` with one
 * id per line). Re-reads each checkout and dispatches
 * {@see CheckoutReconciled} — the listener runs the
 * same idempotent order transition as CheckoutCompleted.
 *
 * Schedule it every five minutes with the ids of your pending orders:
 *
 *   $schedule->call(fn () => DPay::reconciler()->run(Order::pendingCheckoutIds(), $transition))
 *            ->everyFiveMinutes();
 */
final class ReconcileCommand extends Command
{
    protected $signature = 'dpay:reconcile {ids?* : Checkout session ids (cs_… / cs_test_…)} {--limit= : Reads per run (default dpay.reconcile.limit)} {--stdin : Read ids from standard input, one per line}';

    protected $description = 'إعادة قراءة جلسات الدفع المعلّقة / Re-read pending checkout sessions and dispatch CheckoutReconciled for each.';

    public function handle(DPayManager $dpay, Translator $translator): int
    {
        $ids = [];
        foreach ((array) $this->argument('ids') as $id) {
            if (is_scalar($id) && trim((string) $id) !== '') {
                $ids[] = trim((string) $id);
            }
        }
        if ((bool) $this->option('stdin')) {
            while (($line = fgets(STDIN)) !== false) {
                $line = trim($line);
                if ($line !== '') {
                    $ids[] = $line;
                }
            }
        }
        if ($ids === []) {
            $this->bilingual($translator, 'none');

            return self::INVALID;
        }
        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;
        $report = $dpay->reconciler()->run($ids, null, $limit);

        $statuses = [];
        foreach ($report->statuses as $status => $count) {
            $statuses[] = $status.'='.$count;
        }
        $this->bilingual($translator, 'read', ['count' => (string) $report->read(), 'statuses' => $statuses === [] ? '—' : implode(', ', $statuses)]);
        foreach ($report->checkouts as $checkout) {
            $this->line(sprintf('  %s  %-9s  %s %s  ref=%s', $checkout->id, $checkout->status->value, $checkout->amount->format(2), $checkout->currency, $checkout->reference ?? '—'));
        }
        if ($report->notFound !== []) {
            $this->bilingual($translator, 'not_found', ['ids' => implode(', ', $report->notFound)]);
        }
        if ($report->errors !== []) {
            $this->bilingual($translator, 'errors', ['count' => (string) count($report->errors)]);
            foreach ($report->errors as $id => $message) {
                $this->line('  '.$id.'  '.$message);
            }
        }
        if ($report->skipped > 0) {
            $configured = $dpay->config()['reconcile'] ?? null;
            $configuredLimit = is_array($configured) && is_numeric($configured['limit'] ?? null) ? (int) $configured['limit'] : 30;
            $this->bilingual($translator, 'skipped', ['count' => (string) $report->skipped, 'limit' => (string) ($limit ?? $configuredLimit)]);
        }

        return $report->errors === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, string> $params */
    private function bilingual(Translator $translator, string $key, array $params = []): void
    {
        foreach (['ar', 'en'] as $locale) {
            $text = $translator->get('dpay::dpay.reconcile.'.$key, $params, $locale);
            $this->line(is_string($text) ? $text : $key);
        }
    }
}
