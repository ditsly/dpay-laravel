<?php

declare(strict_types=1);

namespace DPay\Laravel\Webhooks;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The `dpay_webhook_events` table (publish `dpay-migrations`): a unique
 * `dedupe_key` column makes the claim a single INSERT that either lands or
 * violates — exactly-once across every worker and database.
 */
final class DatabaseDeduper implements Deduper
{
    public function __construct(private readonly ConnectionInterface $db, private readonly string $table) {}

    public function claim(string $key, string $eventName): bool
    {
        $now = date('Y-m-d H:i:s');
        try {
            return $this->db->table($this->table)->insert([
                'dedupe_key' => $key,
                'event' => $eventName,
                'received_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        } catch (QueryException $e) {
            // Laravel 10 on some drivers raises the base QueryException for a unique violation.
            if (self::isUniqueViolation($e)) {
                return false;
            }
            throw $e;
        }
    }

    public function release(string $key): void
    {
        $this->db->table($this->table)->where('dedupe_key', $key)->delete();
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $message = strtolower($e->getMessage());

        return in_array($code, ['23000', '23505'], true)
            || str_contains($message, 'unique')
            || str_contains($message, 'duplicate');
    }
}
