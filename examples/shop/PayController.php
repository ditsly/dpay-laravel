<?php

declare(strict_types=1);

namespace DPay\Laravel\Examples\Shop;

use DPay\Laravel\CreatedCheckout;
use DPay\Laravel\Facades\DPay;
use DPay\Models\CheckoutStatus;
use DPay\ReturnUrl\ReturnUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Two routes:
 *
 *   Route::post('orders/{order}/pay', [PayController::class, 'pay'])->name('orders.pay');
 *   Route::get('orders/{order}/dpay/return', [PayController::class, 'returned'])->name('orders.dpay.return');
 *
 * plus the package's own POST /dpay/webhook.
 */
final class PayController
{
    public function __construct(private readonly OrderTransition $transition) {}

    /** Step 1 — create the hosted checkout and redirect (303). */
    public function pay(Order $order): CreatedCheckout
    {
        $created = DPay::checkout()
            ->amount($order->total)
            ->forOrder($order->id, $order->payAttempt)          // reference + deterministic Idempotency-Key
            ->description('طلب #'.$order->id)
            ->returnUrl(route('orders.dpay.return', ['order' => $order->id]))
            ->cancelUrl(route('cart'))
            ->metadata(['order_id' => $order->id, 'platform' => 'laravel'])
            ->customer(name: $order->customerName, phone: $order->phone)
            ->create();

        $order->dpayCheckoutId = $created->id;                    // persist id + expiry on the order
        $order->dpayExpiresAt = $created->expiresAt;

        return $created;                                          // Responsable → 303 to the hosted page
    }

    /** Step 2 — the customer is back: the query is a hint, the API is the truth. */
    public function returned(Request $request, Order $order): JsonResponse
    {
        $hint = ReturnUrl::parse($request->query());
        if ($hint->checkoutSessionId === null || $hint->checkoutSessionId !== $order->dpayCheckoutId) {
            return new JsonResponse(['error' => 'unknown checkout'], 404);
        }

        $checkout = DPay::checkoutSessions()->get($hint->checkoutSessionId);
        $this->transition->apply($order, $checkout);

        return new JsonResponse(match ($checkout->status) {
            CheckoutStatus::Paid => ['state' => 'paid', 'tx_id' => $checkout->payment?->txId],
            CheckoutStatus::Open => ['state' => 'retry', 'url' => $checkout->url],   // attempt failed; checkout still payable
            CheckoutStatus::Expired => ['state' => 'expired', 'attempt' => $order->payAttempt],
            CheckoutStatus::Cancelled => ['state' => 'cancelled'],
        });
    }
}
