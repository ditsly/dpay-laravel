<?php

declare(strict_types=1);

namespace DPay\Laravel\Tests\Unit;

use DPay\Laravel\Webhooks\CacheDeduper;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CacheDeduperTest extends TestCase
{
    #[Test]
    public function claimsOnceUntilReleased(): void
    {
        $deduper = new CacheDeduper(new Repository(new ArrayStore()), 3600);
        self::assertTrue($deduper->claim('live:124:payment.paid', 'payment.paid'));
        self::assertFalse($deduper->claim('live:124:payment.paid', 'payment.paid'));
        self::assertTrue($deduper->claim('live:124:payment.refunded', 'payment.refunded'));
        $deduper->release('live:124:payment.paid');
        self::assertTrue($deduper->claim('live:124:payment.paid', 'payment.paid'));
    }
}
