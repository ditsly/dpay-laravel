<?php

declare(strict_types=1);

namespace DPay\Laravel;

use DPay\Exceptions\InvalidArgumentException;
use DPay\Money\Money;
use DPay\Requests\CreateCheckoutSessionRequest;

/**
 * Fluent hosted-checkout creation:
 *
 *   return DPay::checkout()
 *       ->amount($order->total)                 // decimal string, ≤ 2 dp, LYD
 *       ->forOrder($order->id, $order->attempt) // reference + deterministic Idempotency-Key
 *       ->description("Order #{$order->id}")
 *       ->returnUrl(route('shop.dpay.return', $order))
 *       ->cancelUrl(route('shop.cart'))
 *       ->metadata(['order_id' => $order->id])
 *       ->customer(name: $order->name, phone: $order->phone)
 *       ->create()                              // CreatedCheckout — store ->id, ->url, ->expiresAt
 *       ->redirect();                           // 303 to the hosted page
 *
 * Every rule of the request body is validated client-side by the SDK's
 * {@see CreateCheckoutSessionRequest} (amount ≤ 2 dp, https URLs, metadata
 * limits); the API remains the authority.
 */
final class CheckoutBuilder
{
    private Money|string|int|float|null $amount = null;

    private ?string $returnUrl = null;

    private ?string $cancelUrl = null;

    private ?string $description = null;

    private ?string $reference = null;

    /** @var array<string, string|int|float|bool|null>|null */
    private ?array $metadata = null;

    /** @var array{name?: string|null, email?: string|null, phone?: string|null}|null */
    private ?array $customer = null;

    /** @var list<string>|null */
    private ?array $allowedMethods = null;

    private ?int $expiresInMinutes = null;

    private ?string $locale = null;

    private ?string $idempotencyKey = null;

    /** @var array{0: string|int, 1: int}|null */
    private ?array $order = null;

    public function __construct(private readonly DPayManager $manager) {}

    /** The merchant's figure before the per-method fee: `'125.50'`, `125`, or a Money. */
    public function amount(Money|string|int|float $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Sets `reference` to the order id AND derives the Idempotency-Key from
     * `platform:store_uid:order:attempt` — a double-click replays the same
     * checkout; bump `$attempt` for a new one after an expiry.
     */
    public function forOrder(string|int $orderId, int $attempt = 1): self
    {
        $this->reference = (string) $orderId;
        $this->order = [$orderId, $attempt];
        $this->idempotencyKey = null;

        return $this;
    }

    /** The store's order number (≤ 64) — echoed on the object and in every webhook. */
    public function reference(string|int $reference): self
    {
        $this->reference = (string) $reference;

        return $this;
    }

    /** An explicit Idempotency-Key (≤ 64 chars) instead of the derived one. */
    public function idempotencyKey(?string $key): self
    {
        $this->idempotencyKey = $key;
        $this->order = null;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** Absolute https URL the customer lands on after paying (the hosted page appends `checkout_session_id`, `status`, `payment_id`). */
    public function returnUrl(string $url): self
    {
        $this->returnUrl = $url;

        return $this;
    }

    /** Where "return to store" leads; defaults to the return URL. */
    public function cancelUrl(string $url): self
    {
        $this->cancelUrl = $url;

        return $this;
    }

    /** @param array<string, string|int|float|bool|null> $metadata ≤ 50 keys, scalar values */
    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function customer(?string $name = null, ?string $email = null, ?string $phone = null): self
    {
        $this->customer = ['name' => $name, 'email' => $email, 'phone' => $phone];

        return $this;
    }

    /** @param list<string> $slugs only these methods appear on the hosted page */
    public function allowedMethods(array $slugs): self
    {
        $this->allowedMethods = $slugs;

        return $this;
    }

    /** 5…1440 minutes; default 60. */
    public function expiresIn(int $minutes): self
    {
        $this->expiresInMinutes = $minutes;

        return $this;
    }

    /** `ar` | `en` — first-visit language of the hosted page; defaults to `dpay.locale`. */
    public function locale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    /** The validated request body the SDK will send. */
    public function toRequest(): CreateCheckoutSessionRequest
    {
        if ($this->amount === null) {
            throw new InvalidArgumentException('amount is required — DPay::checkout()->amount(\'125.50\').');
        }
        if ($this->returnUrl === null) {
            throw new InvalidArgumentException('returnUrl is required — the https URL the customer comes back to.');
        }
        $request = CreateCheckoutSessionRequest::of($this->amount, $this->returnUrl);
        if ($this->reference !== null) {
            $request = $request->reference($this->reference);
        }
        if ($this->description !== null) {
            $request = $request->description($this->description);
        }
        if ($this->cancelUrl !== null) {
            $request = $request->cancelUrl($this->cancelUrl);
        }
        if ($this->metadata !== null) {
            $request = $request->metadata($this->metadata);
        }
        if ($this->customer !== null) {
            $request = $request->customer($this->customer['name'] ?? null, $this->customer['email'] ?? null, $this->customer['phone'] ?? null);
        }
        if ($this->allowedMethods !== null) {
            $request = $request->allowedMethods($this->allowedMethods);
        }
        if ($this->expiresInMinutes !== null) {
            $request = $request->expiresInMinutes($this->expiresInMinutes);
        }
        $request = $request->locale($this->locale ?? $this->manager->locale());

        return $request;
    }

    /** The Idempotency-Key that will be sent, or null for none. */
    public function resolvedIdempotencyKey(): ?string
    {
        if ($this->idempotencyKey !== null) {
            return $this->idempotencyKey;
        }
        if ($this->order !== null) {
            return $this->manager->keys()->forCheckout($this->order[0], $this->order[1]);
        }

        return null;
    }

    /** `POST /api/v2/checkout-sessions` — persist id/url/expiresAt on the order, then redirect. */
    public function create(): CreatedCheckout
    {
        $session = $this->manager->checkoutSessions()->create($this->toRequest(), $this->resolvedIdempotencyKey());

        return new CreatedCheckout($session);
    }
}
