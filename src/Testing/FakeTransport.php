<?php

declare(strict_types=1);

namespace DPay\Laravel\Testing;

use DPay\Config\Environment;
use DPay\Http\ApiResponse;
use DPay\Http\TransportInterface;

/**
 * An in-memory DPay API for tests. It answers the real SDK with the shapes
 * the platform sends (v2 `{data, meta}` envelopes, RFC 7807 problems, the
 * legacy `{message}` bodies), records every request, and lets a test move
 * a checkout to paid/expired/cancelled between calls.
 *
 * Nothing here is reachable in production: the transport is injected only
 * by {@see DPayFake}.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<RecordedRequest> */
    public array $requests = [];

    /** @var array<string, array<string, mixed>> id → session object */
    private array $sessions = [];

    /** @var array<string, array{hash: string, id: string}> Idempotency-Key → original */
    private array $idempotency = [];

    /** @var list<array{method: string, pattern: string, status: int, body: mixed, headers: array<string, string>, once: bool}> */
    private array $canned = [];

    /** @var list<array<string, mixed>> */
    private array $payMethods;

    private int $sequence = 800;

    public function __construct(
        private readonly Environment $environment,
        private readonly string $baseUrl = 'https://dpay.ly',
    ) {
        $this->payMethods = self::defaultPayMethods($environment);
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): ApiResponse
    {
        $request = new RecordedRequest($method, $url, $headers, $body);
        $this->requests[] = $request;
        $path = $request->path();

        foreach ($this->canned as $i => $canned) {
            if ($canned['method'] === strtoupper($method) && preg_match($canned['pattern'], $path) === 1) {
                if ($canned['once']) {
                    array_splice($this->canned, $i, 1);
                }

                return self::reply($canned['status'], $canned['body'], $canned['headers']);
            }
        }

        $prefix = $this->environment->pathPrefix();

        return match (true) {
            $method === 'GET' && $path === '/api/health' => self::reply(200, ['status' => 'ok', 'timestamp' => gmdate('c'), 'database' => true, 'cache' => true]),
            $method === 'GET' && $path === $prefix.'/pay-methods' => self::reply(200, $this->environment->payMethodsAreEnveloped() ? ['data' => $this->payMethods] : $this->payMethods),
            $method === 'POST' && $path === '/api/v2/checkout-sessions' => $this->create($request),
            $method === 'GET' && $path === '/api/v2/checkout-sessions' => $this->list($request),
            $method === 'GET' && preg_match('#^/api/v2/checkout-sessions/([^/]+)$#', $path, $m) === 1 => $this->get(rawurldecode($m[1])),
            $method === 'POST' && preg_match('#^/api/v2/checkout-sessions/([^/]+)/cancel$#', $path, $m) === 1 => $this->cancel(rawurldecode($m[1])),
            default => self::problem(404, 'not-found', 'Not Found', sprintf('The fake DPay API has no handler for %s %s — queue one with respondWith().', $method, $path)),
        };
    }

    // ---- scripting -------------------------------------------------------

    /**
     * Queue a canned answer for `{method} {path regex}` (checked before the
     * built-in handlers). `$once` false keeps it for every matching call.
     *
     * @param  array<string, string>  $headers
     */
    public function respondWith(string $method, string $pathPattern, int $status, mixed $body, array $headers = [], bool $once = true): void
    {
        $this->canned[] = ['method' => strtoupper($method), 'pattern' => $pathPattern, 'status' => $status, 'body' => $body, 'headers' => $headers, 'once' => $once];
    }

    /** @param list<array<string, mixed>> $rows */
    public function setPayMethods(array $rows): void
    {
        $this->payMethods = $rows;
    }

    /**
     * Move a checkout to `paid` with a winning attempt.
     *
     * @param  array<string, mixed>  $payment  overrides of session_id, pay_method, tx_id, amount_charged, fee_amount, fee_percent, paid_at, receipt_url
     */
    public function markPaid(string $id, array $payment = []): void
    {
        $session = $this->sessions[$id] ?? throw new \InvalidArgumentException(sprintf('Unknown fake checkout "%s".', $id));
        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $sid = $this->sequence++;
        $amount = self::float($session['amount'] ?? null);
        $fee = round($amount * 0.01, 3);
        $charged = number_format(round($amount + $fee, 2), 2, '.', '');
        $payment = array_replace([
            'session_id' => $sid,
            'pay_method' => 'edfali',
            'tx_id' => 'txn_'.bin2hex(random_bytes(4)),
            'amount_charged' => $charged,
            'fee_amount' => number_format($fee, 3, '.', ''),
            'fee_percent' => '1.000',
            'paid_at' => $now,
            'receipt_url' => $this->environment->isLive() ? $this->baseUrl.'/receipt/'.$sid.'/'.bin2hex(random_bytes(8)) : null,
        ], $payment);
        $session['status'] = 'paid';
        $session['paid_at'] = $now;
        $session['updated_at'] = $now;
        $session['payment'] = $payment;
        $previous = isset($session['attempts']) && is_array($session['attempts']) ? $session['attempts'] : [];
        $session['attempts'] = array_merge([[
            'session_id' => $payment['session_id'],
            'pay_method' => $payment['pay_method'],
            'status' => 'paid',
            'created_at' => $now,
        ]], $previous);
        $this->sessions[$id] = $session;
    }

    public function markExpired(string $id): void
    {
        $session = $this->sessions[$id] ?? throw new \InvalidArgumentException(sprintf('Unknown fake checkout "%s".', $id));
        $session['status'] = 'expired';
        $session['updated_at'] = gmdate('Y-m-d\TH:i:s.000\Z');
        $this->sessions[$id] = $session;
    }

    public function markCancelled(string $id): void
    {
        $session = $this->sessions[$id] ?? throw new \InvalidArgumentException(sprintf('Unknown fake checkout "%s".', $id));
        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $session['status'] = 'cancelled';
        $session['cancelled_at'] = $now;
        $session['updated_at'] = $now;
        $this->sessions[$id] = $session;
    }

    /** @return array<string, mixed>|null */
    public function session(string $id): ?array
    {
        return $this->sessions[$id] ?? null;
    }

    /** @return list<array<string, mixed>> newest first */
    public function sessions(): array
    {
        return array_values(array_reverse($this->sessions));
    }

    /** Seed a checkout as if it had been created earlier (e.g. before a webhook test). */
    public function seed(string $id, string $amount, string $reference, string $returnUrl = 'https://shop.example.ly/dpay/return', string $status = 'open'): void
    {
        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $this->sessions[$id] = [
            'id' => $id,
            'url' => $this->hostedUrl($id),
            'status' => $status,
            'live' => $this->environment->isLive(),
            'amount' => number_format((float) $amount, 2, '.', ''),
            'currency' => 'LYD',
            'description' => null,
            'reference' => $reference,
            'return_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'metadata' => [],
            'customer' => null,
            'allowed_methods' => null,
            'locale' => 'ar',
            'expires_at' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 3600),
            'paid_at' => null,
            'cancelled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'payment' => null,
            'attempts' => [],
        ];
    }

    public function newId(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $ulid = '';
        for ($i = 0; $i < 26; $i++) {
            $ulid .= $alphabet[random_int(0, 31)];
        }

        return $this->environment->checkoutIdPrefix().$ulid;
    }

    // ---- handlers --------------------------------------------------------

    private function create(RecordedRequest $request): ApiResponse
    {
        $body = $request->json ?? [];
        $key = $request->idempotencyKey();
        $hash = hash('sha256', (string) json_encode(self::sorted($body)));
        if ($key !== null && isset($this->idempotency[$key])) {
            if ($this->idempotency[$key]['hash'] !== $hash) {
                return self::problem(409, 'conflict', 'Conflict', 'Idempotency-Key was already used with a different body.', ['code' => 'idempotency_key_reused']);
            }

            return self::reply(200, ['data' => $this->sessions[$this->idempotency[$key]['id']], 'meta' => ['idempotent_replay' => true]]);
        }
        $errors = [];
        $amount = $body['amount'] ?? null;
        $amount = is_numeric($amount) ? (string) $amount : '';
        if ($amount === '' || (float) $amount < 0.01 || preg_match('/^\d+(\.\d{1,2})?$/', $amount) !== 1) {
            $errors['amount'] = ['amount must be a decimal with at most 2 places and at least 0.01.'];
        }
        $returnUrl = $body['return_url'] ?? null;
        if (! is_string($returnUrl) || ! str_starts_with($returnUrl, 'https://')) {
            $errors['return_url'] = ['return_url must be an absolute https URL.'];
        }
        if ($errors !== []) {
            return self::problem(422, 'validation', 'Unprocessable Entity', 'Validation failed.', ['errors' => $errors]);
        }
        $id = $this->newId();
        $now = gmdate('Y-m-d\TH:i:s.000\Z');
        $minutes = is_int($body['expires_in_minutes'] ?? null) ? $body['expires_in_minutes'] : 60;
        $customer = isset($body['customer']) && is_array($body['customer']) ? [
            'name' => $body['customer']['name'] ?? null,
            'email' => $body['customer']['email'] ?? null,
            'phone' => $body['customer']['phone'] ?? null,
        ] : null;
        $metadata = isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : [];
        $session = [
            'id' => $id,
            'url' => $this->hostedUrl($id),
            'status' => 'open',
            'live' => $this->environment->isLive(),
            'amount' => number_format((float) $amount, 2, '.', ''),
            'currency' => 'LYD',
            'description' => $body['description'] ?? null,
            'reference' => is_scalar($body['reference'] ?? null) ? (string) $body['reference'] : null,
            'return_url' => $returnUrl,
            'cancel_url' => $body['cancel_url'] ?? $returnUrl,
            'metadata' => $metadata,
            'customer' => $customer,
            'allowed_methods' => $body['allowed_methods'] ?? null,
            'locale' => $body['locale'] ?? 'ar',
            'expires_at' => gmdate('Y-m-d\TH:i:s.000\Z', time() + $minutes * 60),
            'paid_at' => null,
            'cancelled_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'payment' => null,
            'attempts' => [],
        ];
        $this->sessions[$id] = $session;
        if ($key !== null) {
            $this->idempotency[$key] = ['hash' => $hash, 'id' => $id];
        }

        return self::reply(201, ['data' => $session, 'meta' => ['idempotent_replay' => false]]);
    }

    private function get(string $id): ApiResponse
    {
        $session = $this->sessions[$id] ?? null;
        if ($session === null) {
            return self::problem(404, 'not-found', 'Not Found', 'Checkout session not found.');
        }

        return self::reply(200, ['data' => $session]);
    }

    private function cancel(string $id): ApiResponse
    {
        $session = $this->sessions[$id] ?? null;
        if ($session === null) {
            return self::problem(404, 'not-found', 'Not Found', 'Checkout session not found.');
        }
        $status = is_string($session['status'] ?? null) ? $session['status'] : 'unknown';
        if ($status !== 'open') {
            return self::problem(409, 'conflict', 'Conflict', sprintf('Checkout session is %s.', $status), ['code' => 'checkout_not_open', 'checkout_status' => $status]);
        }
        $this->markCancelled($id);

        return self::reply(200, ['data' => $this->sessions[$id]]);
    }

    private function list(RecordedRequest $request): ApiResponse
    {
        $query = $request->query();
        $rows = [];
        foreach ($this->sessions() as $session) {
            if (isset($query['status']) && $query['status'] !== '' && $session['status'] !== $query['status']) {
                continue;
            }
            if (isset($query['reference']) && $query['reference'] !== '' && $session['reference'] !== $query['reference']) {
                continue;
            }
            unset($session['attempts']);
            $rows[] = $session;
        }
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? max(1, (int) $query['limit']) : 25;
        $page = array_slice($rows, 0, $limit);

        return self::reply(200, ['data' => $page, 'meta' => ['limit' => $limit, 'has_more' => count($rows) > $limit, 'next_cursor' => count($rows) > $limit ? 'cursor_'.$limit : null]]);
    }

    // ---- helpers ---------------------------------------------------------

    private function hostedUrl(string $id): string
    {
        return $this->baseUrl.($this->environment->isLive() ? '/pay/' : '/sandbox/pay/').$id;
    }

    /** @param array<string, string> $headers */
    private static function reply(int $status, mixed $body, array $headers = []): ApiResponse
    {
        $flat = ['content-type' => 'application/json', 'x-request-id' => 'fake-'.bin2hex(random_bytes(4))];
        foreach ($headers as $k => $v) {
            $flat[strtolower($k)] = $v;
        }
        $raw = is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new ApiResponse($status, $flat, $raw);
    }

    /** @param array<string, mixed> $extra */
    private static function problem(int $status, string $type, string $title, string $detail, array $extra = []): ApiResponse
    {
        return self::reply($status, array_merge([
            'type' => '/api/v2/problems/'.$type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ], $extra), ['content-type' => 'application/problem+json']);
    }

    private static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    private static function sorted(array $a): array
    {
        ksort($a);
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                /** @var array<string, mixed> $v */
                $a[$k] = self::sorted($v);
            }
        }

        return $a;
    }

    /** @return list<array<string, mixed>> */
    private static function defaultPayMethods(Environment $environment): array
    {
        $rows = [
            ['id' => 1, 'name' => 'Edfali', 'slug' => 'edfali', 'tag' => 'edfali', 'icon' => 'edfali.svg', 'logo_url' => 'https://dpay.ly/assets/img/logos/edfali.svg', 'currency' => 'LYD', 'fee' => 1, 'min_deposit' => 5, 'max_deposit' => 60000, 'active' => true, 'configured' => true, 'enabled' => true, 'cross_bank_enabled' => false, 'otp_length' => 4],
            ['id' => 2, 'name' => 'MobiCash', 'slug' => 'mobicash', 'tag' => 'mobicash', 'icon' => 'mobicash.svg', 'logo_url' => 'https://dpay.ly/assets/img/logos/mobicash.svg', 'currency' => 'LYD', 'fee' => 1, 'min_deposit' => 5, 'max_deposit' => 60000, 'active' => true, 'configured' => true, 'enabled' => true, 'cross_bank_enabled' => false, 'otp_length' => 6],
            ['id' => 7, 'name' => 'Moamalat', 'slug' => 'moamalat', 'tag' => 'moamalat', 'icon' => 'moamalat.svg', 'logo_url' => 'https://dpay.ly/assets/img/logos/moamalat.svg', 'currency' => 'LYD', 'fee' => 1.5, 'min_deposit' => 1, 'max_deposit' => 60000, 'active' => true, 'configured' => true, 'enabled' => true, 'cross_bank_enabled' => false, 'otp_length' => null],
            ['id' => 8, 'name' => 'Sadad', 'slug' => 'sadad', 'tag' => 'sadad', 'icon' => 'sadad.svg', 'logo_url' => 'https://dpay.ly/assets/img/logos/sadad.svg', 'currency' => 'LYD', 'fee' => 1, 'min_deposit' => 5, 'max_deposit' => 60000, 'active' => true, 'configured' => true, 'enabled' => true, 'cross_bank_enabled' => false, 'otp_length' => 6],
        ];
        if (! $environment->isSandbox()) {
            return $rows;
        }
        // The sandbox list is a bare array with the legacy keys only (`tag`, no `slug`).
        $sandbox = [];
        foreach ($rows as $r) {
            if ($environment->supportsSlug($r['slug'])) {
                $sandbox[] = ['name' => $r['name'], 'active' => true, 'tag' => $r['tag'], 'fee' => $r['fee'], 'min_deposit' => $r['min_deposit'], 'max_deposit' => $r['max_deposit'], 'enabled' => true];
            }
        }

        return $sandbox;
    }
}
