<?php

declare(strict_types=1);

namespace DPay\Laravel\Webhooks;

/** No suppression — every delivery is dispatched; your listeners dedupe. */
final class NullDeduper implements Deduper
{
    public function claim(string $key, string $eventName): bool
    {
        return true;
    }

    public function release(string $key): void {}
}
