<?php

declare(strict_types=1);

namespace DPay\Laravel\Webhooks;

use Illuminate\Contracts\Cache\Repository;

/** `Cache::add()` — atomic on redis, memcached, database, dynamodb; best effort on file/array. */
final class CacheDeduper implements Deduper
{
    public function __construct(private readonly Repository $cache, private readonly int $ttlSeconds) {}

    public function claim(string $key, string $eventName): bool
    {
        return $this->cache->add(self::cacheKey($key), $eventName, $this->ttlSeconds > 0 ? $this->ttlSeconds : null);
    }

    public function release(string $key): void
    {
        $this->cache->forget(self::cacheKey($key));
    }

    public static function cacheKey(string $key): string
    {
        return 'dpay:webhook:'.hash('sha256', $key);
    }
}
