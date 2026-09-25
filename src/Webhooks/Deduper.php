<?php

declare(strict_types=1);

namespace DPay\Laravel\Webhooks;

/**
 * Duplicate suppression on the SDK's dedupe key (`{live}:{id}:{event}`).
 * `claim()` is atomic: exactly one of two concurrent deliveries gets true.
 * `release()` undoes a claim when the listeners failed, so DPay's retry is
 * processed instead of being swallowed as a duplicate.
 */
interface Deduper
{
    public function claim(string $key, string $eventName): bool;

    public function release(string $key): void;
}
