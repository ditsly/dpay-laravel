<?php

declare(strict_types=1);

namespace DPay\Laravel\Examples\Shop;

/** Stand-in for `Order::where('dpay_checkout_id', $id)->first()`. */
final class OrderRepository
{
    /** @var array<string, Order> */
    private static array $orders = [];

    public static function remember(Order $order): void
    {
        if ($order->dpayCheckoutId !== null) {
            self::$orders[$order->dpayCheckoutId] = $order;
        }
    }

    public static function byCheckoutId(?string $checkoutId): ?Order
    {
        return $checkoutId === null ? null : (self::$orders[$checkoutId] ?? null);
    }
}
