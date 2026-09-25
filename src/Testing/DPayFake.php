<?php

declare(strict_types=1);

namespace DPay\Laravel\Testing;

use DPay\Config\Environment;
use DPay\Http\RetryPolicy;
use DPay\Laravel\DPayManager;
use DPay\Webhooks\Signature;
use PHPUnit\Framework\Assert;

/**
 * `DPay::fake()` — the manager with an in-memory API behind it.
 *
 *   $fake = DPay::fake();
 *   $this->post(route('shop.pay', $order))->assertRedirect();
 *   $fake->assertCheckoutCreated(fn (RecordedRequest $r) => $r->json['reference'] === '10483');
 *   $fake->markPaid($fake->lastCheckoutId());
 *   $this->get(route('shop.dpay.return', ['order' => 10483, 'checkout_session_id' => $id, 'status' => 'paid']));
 *
 * Every SDK call still runs for real (request building, validation, model
 * parsing); only the HTTP layer is swapped, so what your code SENDS is
 * asserted byte for byte. Nothing reaches the network.
 */
final class DPayFake extends DPayManager
{
    public readonly FakeTransport $api;

    /** @param array<string, mixed> $config the `dpay` config (with any test overrides applied) */
    public function __construct(array $config)
    {
        $config['mode'] ??= 'sandbox';
        $mode = is_scalar($config['mode']) ? strtolower((string) $config['mode']) : 'sandbox';
        if ($mode === 'live' && empty($config['api_token'])) {
            $config['api_token'] = '1|'.str_repeat('a', 40).'deadbeef';
        }
        if ($mode !== 'live' && empty($config['sandbox_token'])) {
            $config['sandbox_token'] = 'sb_tk_'.str_repeat('f', 32);
        }
        if (empty($config['store_uid'])) {
            $config['store_uid'] = 'test-store-uid-0123456789abcdef';
        }
        $config['webhook'] ??= [];
        if (is_array($config['webhook']) && empty($config['webhook']['secret'])) {
            $config['webhook']['secret'] = 'whsec_'.str_repeat('0123456789abcdef', 4);
        }
        $baseUrl = is_string($config['base_url'] ?? null) ? $config['base_url'] : 'https://dpay.ly';
        $transport = new FakeTransport($mode === 'live' ? Environment::Live : Environment::Sandbox, rtrim($baseUrl, '/'));
        // Real retry decisions, no real sleeping: a 503 is retried and then surfaces, in milliseconds.
        parent::__construct($config, null, $transport, new RetryPolicy(sleeper: static function (float $seconds): void {}));
        $this->api = $transport;
    }

    // ---- scripting -------------------------------------------------------

    /** @param array<string, mixed> $payment */
    public function markPaid(string $id, array $payment = []): self
    {
        $this->api->markPaid($id, $payment);

        return $this;
    }

    public function markExpired(string $id): self
    {
        $this->api->markExpired($id);

        return $this;
    }

    public function markCancelled(string $id): self
    {
        $this->api->markCancelled($id);

        return $this;
    }

    /** A checkout that "already exists" on the API (for return-handler and reconcile tests). */
    public function seedCheckout(string $amount, string $reference, string $status = 'open', ?string $id = null): string
    {
        $id ??= $this->api->newId();
        $this->api->seed($id, $amount, $reference, 'https://shop.example.ly/dpay/return', $status);

        return $id;
    }

    /**
     * Queue a canned answer (checked before the built-in handlers): e.g.
     * `$fake->respondWith('POST', '#/checkout-sessions$#', 429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '30'])`.
     *
     * @param  array<string, string>  $headers
     */
    public function respondWith(string $method, string $pathPattern, int $status, mixed $body, array $headers = [], bool $once = true): self
    {
        $this->api->respondWith($method, $pathPattern, $status, $body, $headers, $once);

        return $this;
    }

    // ---- inspection ------------------------------------------------------

    /** @return list<RecordedRequest> */
    public function requests(): array
    {
        return $this->api->requests;
    }

    /** @return list<RecordedRequest> */
    public function checkoutCreations(): array
    {
        return array_values(array_filter($this->api->requests, static fn (RecordedRequest $r): bool => $r->is('POST', '/api/v2/checkout-sessions')));
    }

    /** The id of the most recently created checkout, or null. */
    public function lastCheckoutId(): ?string
    {
        $sessions = $this->api->sessions();

        $id = $sessions === [] ? null : ($sessions[0]['id'] ?? null);

        return is_string($id) ? $id : null;
    }

    /** @return array<string, mixed>|null the fake API's current view of a checkout */
    public function checkoutState(string $id): ?array
    {
        return $this->api->session($id);
    }

    // ---- assertions ------------------------------------------------------

    /** @param (callable(RecordedRequest): bool)|null $predicate */
    public function assertCheckoutCreated(?callable $predicate = null, string $message = ''): self
    {
        $creations = $this->checkoutCreations();
        Assert::assertNotEmpty($creations, $message !== '' ? $message : 'No checkout session was created through DPay.');
        if ($predicate !== null) {
            $matched = array_filter($creations, static fn (RecordedRequest $r): bool => (bool) $predicate($r));
            Assert::assertNotEmpty($matched, $message !== '' ? $message : 'A checkout session was created, but none matched the predicate.');
        }

        return $this;
    }

    public function assertCheckoutCreatedCount(int $count): self
    {
        Assert::assertCount($count, $this->checkoutCreations(), sprintf('Expected %d checkout creation(s).', $count));

        return $this;
    }

    public function assertNoCheckoutCreated(): self
    {
        Assert::assertEmpty($this->checkoutCreations(), 'A checkout session was created through DPay, but none was expected.');

        return $this;
    }

    public function assertNothingSent(): self
    {
        Assert::assertEmpty($this->api->requests, sprintf('%d request(s) reached the DPay API; none were expected.', count($this->api->requests)));

        return $this;
    }

    /** @param (callable(RecordedRequest): bool)|null $predicate */
    public function assertRequested(string $method, string $path, ?callable $predicate = null): self
    {
        $matched = array_filter($this->api->requests, static fn (RecordedRequest $r): bool => $r->is($method, $path) && ($predicate === null || (bool) $predicate($r)));
        Assert::assertNotEmpty($matched, sprintf('No %s %s request reached the DPay API.', strtoupper($method), $path));

        return $this;
    }

    public function assertNotRequested(string $method, string $path): self
    {
        $matched = array_filter($this->api->requests, static fn (RecordedRequest $r): bool => $r->is($method, $path));
        Assert::assertEmpty($matched, sprintf('A %s %s request reached the DPay API; none was expected.', strtoupper($method), $path));

        return $this;
    }

    public function assertCheckoutRead(string $id): self
    {
        return $this->assertRequested('GET', '/api/v2/checkout-sessions/'.$id);
    }

    public function assertCheckoutCancelled(string $id): self
    {
        $this->assertRequested('POST', '/api/v2/checkout-sessions/'.$id.'/cancel');
        Assert::assertSame('cancelled', $this->api->session($id)['status'] ?? null, sprintf('Checkout %s is not cancelled on the fake API.', $id));

        return $this;
    }

    /** Every creation carried an Idempotency-Key (deterministic per order). */
    public function assertIdempotencyKeysSent(): self
    {
        foreach ($this->checkoutCreations() as $r) {
            Assert::assertNotNull($r->idempotencyKey(), 'A checkout was created without an Idempotency-Key — use ->forOrder($id, $attempt).');
        }

        return $this;
    }

    // ---- webhooks in tests -----------------------------------------------

    /**
     * Sign a payload the way DPay would, for `$this->call('POST', $path, [], [], [], $headers, $raw)`:
     * returns `[rawBody, serverHeaders]` with HTTP_X_DPAY_* keys.
     *
     * @param  array<string, mixed>|string  $payload
     * @return array{0: string, 1: array<string, string>}
     */
    public function signedWebhook(array|string $payload, ?int $timestamp = null, ?string $secret = null): array
    {
        $raw = is_string($payload) ? $payload : (string) json_encode($payload);
        $ts = (string) ($timestamp ?? time());
        $secret ??= $this->webhookSecrets()[0] ?? 'whsec_'.str_repeat('0', 64);
        $event = 'unknown';
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && is_string($decoded['event'] ?? null)) {
            $event = $decoded['event'];
        }

        return [$raw, [
            'HTTP_X_DPAY_TIMESTAMP' => $ts,
            'HTTP_X_DPAY_EVENT' => $event,
            'HTTP_X_DPAY_SIGNATURE' => Signature::compute($ts, $raw, $secret),
            'HTTP_USER_AGENT' => 'DPAY-Webhooks/1.0',
            'CONTENT_TYPE' => 'application/json',
        ]];
    }

    private static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * A `checkout.completed` body for a checkout the fake knows, in DPay's key order.
     *
     * @return array<string, mixed>
     */
    public function checkoutCompletedPayload(string $id): array
    {
        $session = $this->api->session($id) ?? throw new \InvalidArgumentException(sprintf('Unknown fake checkout "%s".', $id));
        $payment = $session['payment'] ?? null;
        if (! is_array($payment)) {
            throw new \LogicException(sprintf('Checkout %s is not paid — call markPaid() first.', $id));
        }

        return [
            'event' => 'checkout.completed',
            'live' => (bool) $session['live'],
            'checkout_session_id' => $id,
            'reference' => $session['reference'],
            'status' => 'paid',
            'amount' => self::float($session['amount'] ?? null),
            'currency' => $session['currency'],
            'description' => $session['description'],
            'metadata' => $session['metadata'] === [] ? new \stdClass() : $session['metadata'],
            'customer' => $session['customer'],
            'payment' => [
                'session_id' => $payment['session_id'],
                'pay_method' => $payment['pay_method'],
                'tx_id' => $payment['tx_id'],
                'amount_charged' => self::float($payment['amount_charged'] ?? null),
                'fee_amount' => self::float($payment['fee_amount'] ?? null),
                'paid_at' => $payment['paid_at'],
                'receipt_url' => $payment['receipt_url'],
            ],
            'created_at' => $session['created_at'],
            'occurred_at' => $payment['paid_at'],
        ];
    }
}
