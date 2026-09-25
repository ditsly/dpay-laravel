<?php

declare(strict_types=1);

namespace DPay\Laravel;

use DPay\Models\CheckoutSession;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The answer of {@see CheckoutBuilder::create()}: the {@see CheckoutSession}
 * plus the redirect. Return it straight from a controller — it is
 * {@see Responsable} and becomes a 303 to the hosted page.
 *
 *   $created = DPay::checkout()->…->create();
 *   $order->update(['dpay_checkout_id' => $created->id, 'dpay_expires_at' => $created->expiresAt]);
 *   return $created;                    // or ->redirect()
 */
final class CreatedCheckout implements Responsable
{
    public readonly string $id;

    public readonly string $url;

    public readonly ?\DateTimeImmutable $expiresAt;

    /** True when the Idempotency-Key replayed an existing checkout (same id, same url). */
    public readonly bool $replayed;

    public function __construct(public readonly CheckoutSession $session)
    {
        $this->id = $session->id;
        $this->url = $session->url;
        $this->expiresAt = $session->expiresAt;
        $this->replayed = $session->idempotentReplay;
    }

    /** 303 See Other to the hosted page (a POST → GET redirect that no browser re-posts). */
    public function redirect(int $status = 303): RedirectResponse
    {
        return new RedirectResponse($this->url, $status);
    }

    /** @param Request $request */
    public function toResponse($request): RedirectResponse
    {
        return $this->redirect();
    }
}
