<?php

declare(strict_types=1);

namespace DPay\Laravel;

use DPay\Client;
use DPay\Config\Config;
use DPay\Config\Environment;
use DPay\Exceptions\InvalidArgumentException;
use DPay\Http\BaseUrlPolicy;
use DPay\Http\PsrTransport;
use DPay\Http\RetryPolicy;
use DPay\Http\TransportInterface;
use DPay\Idempotency\KeyFactory;
use DPay\Laravel\Exceptions\NotConfiguredException;
use DPay\Laravel\Testing\DPayFake;
use DPay\Laravel\Testing\FakeTransport;
use DPay\Resources\CheckoutSessions;
use DPay\Resources\Payments;
use DPay\Resources\PaymentSessions;
use DPay\Resources\PayMethods;
use DPay\Webhooks\Verifier;
use Psr\Log\LoggerInterface;

/**
 * The object behind the `DPay` facade: one SDK client built lazily from
 * `config/dpay.php`, the webhook verifier, the idempotency key factory and
 * the fluent checkout builder.
 *
 *   DPay::checkout()->amount('125.50')->forOrder($order->id)->returnUrl(...)->create()->redirect();
 *   DPay::checkoutSessions()->get($id);
 *   DPay::verifier()->verify($request->getContent(), $request->headers->all());
 *
 * Nothing here touches the network until a resource is called, so `php
 * artisan` works with an empty .env; the first API call on a missing token
 * throws {@see NotConfiguredException}.
 */
class DPayManager
{
    private ?Client $client = null;

    private ?Verifier $verifier = null;

    private ?KeyFactory $keys = null;

    /**
     * @param  array<string, mixed>  $config  the `dpay` config array
     * @param  TransportInterface|null  $transport  injected by {@see DPayFake}; null discovers Guzzle
     * @param  RetryPolicy|null  $retry  null = the SDK default (GETs and keyed POSTs on 429/503, with backoff)
     */
    public function __construct(
        protected array $config,
        protected ?LoggerInterface $logger = null,
        protected ?TransportInterface $transport = null,
        protected ?RetryPolicy $retry = null,
    ) {}

    /** `live` | `sandbox`, validated. */
    public function mode(): string
    {
        $raw = $this->config['mode'] ?? 'sandbox';
        $mode = strtolower(trim(is_scalar($raw) ? (string) $raw : ''));
        if (! in_array($mode, ['live', 'sandbox'], true)) {
            throw NotConfiguredException::mode($mode);
        }

        return $mode;
    }

    public function environment(): Environment
    {
        return $this->mode() === 'live' ? Environment::Live : Environment::Sandbox;
    }

    public function isLive(): bool
    {
        return $this->environment()->isLive();
    }

    public function isSandbox(): bool
    {
        return $this->environment()->isSandbox();
    }

    /** The token for the configured mode, or null when unset. */
    public function token(): ?string
    {
        $key = $this->mode() === 'live' ? 'api_token' : 'sandbox_token';
        $token = $this->config[$key] ?? null;
        $token = is_string($token) ? trim($token) : null;

        return $token === '' ? null : $token;
    }

    /** The .env key the configured mode reads its token from. */
    public function tokenEnvKey(): string
    {
        return $this->mode() === 'live' ? 'DPAY_API_TOKEN' : 'DPAY_SANDBOX_TOKEN';
    }

    public function baseUrlPolicy(): BaseUrlPolicy
    {
        $hosts = [];
        foreach ((array) ($this->config['allowed_hosts'] ?? []) as $host) {
            if (is_string($host) && trim($host) !== '') {
                $hosts[] = trim($host);
            }
        }
        $localhost = (bool) ($this->config['allow_http_localhost'] ?? false);

        return ($hosts === [] && ! $localhost) ? BaseUrlPolicy::dpayOnly() : BaseUrlPolicy::allowing($hosts, $localhost);
    }

    public function baseUrl(): string
    {
        $url = $this->config['base_url'] ?? BaseUrlPolicy::DEFAULT_BASE_URL;

        return is_string($url) && trim($url) !== '' ? trim($url) : BaseUrlPolicy::DEFAULT_BASE_URL;
    }

    public function locale(): string
    {
        $locale = $this->config['locale'] ?? 'ar';

        return in_array($locale, ['ar', 'en'], true) ? $locale : 'ar';
    }

    /**
     * The SDK client for the configured mode (built once).
     *
     * @throws NotConfiguredException when the mode's token is unset
     * @throws InvalidArgumentException when the token, base URL or timeouts are refused by the SDK
     */
    public function client(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $token = $this->token();
        if ($token === null) {
            throw NotConfiguredException::token($this->mode(), $this->tokenEnvKey());
        }
        $config = new Config(
            token: $token,
            environment: $this->environment(),
            baseUrl: $this->baseUrl(),
            baseUrlPolicy: $this->baseUrlPolicy(),
            timeout: self::floatOr($this->config['timeout'] ?? null, Config::DEFAULT_TIMEOUT),
            connectTimeout: self::floatOr($this->config['connect_timeout'] ?? null, Config::DEFAULT_CONNECT_TIMEOUT),
            retry: $this->retry,
            logger: $this->logger,
            locale: $this->locale(),
        );

        return $this->client = new Client($config, $this->transport);
    }

    /**
     * The HTTP client the SDK will send with, for `dpay:doctor` and logs:
     * `class` and whether the SDK could verify it (redirects off, TLS on —
     * true for Guzzle, null for a client whose settings the SDK cannot read,
     * false when nothing is injected and no PSR-18 client is installed).
     *
     * @return array{class: string, hardened: bool|null}
     */
    public function httpClient(): array
    {
        $transport = $this->transport;
        if ($transport === null) {
            try {
                $transport = PsrTransport::discover();
            } catch (\Throwable $e) {
                return ['class' => 'none ('.$e->getMessage().')', 'hardened' => false];
            }
        }
        if ($transport instanceof PsrTransport) {
            return ['class' => $transport->clientClass(), 'hardened' => $transport->isHardened()];
        }
        if ($transport instanceof FakeTransport) {
            return ['class' => $transport::class, 'hardened' => true]; // in-memory: nothing reaches the network
        }

        return ['class' => $transport::class, 'hardened' => null];
    }

    /** Hosted checkout — the recommended integration. */
    public function checkout(): CheckoutBuilder
    {
        return new CheckoutBuilder($this);
    }

    public function checkoutSessions(): CheckoutSessions
    {
        return $this->client()->checkoutSessions();
    }

    public function payMethods(): PayMethods
    {
        return $this->client()->payMethods();
    }

    /** Raw per-method surface (open/verify/get) — see the SDK README before using it. */
    public function paymentSessions(): PaymentSessions
    {
        return $this->client()->paymentSessions();
    }

    public function payments(): Payments
    {
        return $this->client()->payments();
    }

    /** Re-read pending checkouts and run one idempotent transition for each. */
    public function reconciler(): Reconciler
    {
        return new Reconciler($this);
    }

    /** @return list<string> the current secret first, then the previous one during a rotation */
    public function webhookSecrets(): array
    {
        $secrets = [];
        $webhook = $this->rawWebhookConfig();
        foreach (['secret', 'previous_secret'] as $key) {
            $value = $webhook[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $secrets[] = trim($value);
            }
        }

        return $secrets;
    }

    /** The webhook verifier: raw bytes + hash_equals + 300 s window + the live flag against the mode. */
    public function verifier(): Verifier
    {
        if ($this->verifier !== null) {
            return $this->verifier;
        }
        $secrets = $this->webhookSecrets();
        if ($secrets === []) {
            throw NotConfiguredException::webhookSecret();
        }
        $raw = $this->webhookConfig()['tolerance'] ?? null;
        $tolerance = is_numeric($raw) ? (int) $raw : Verifier::DEFAULT_TOLERANCE;

        return $this->verifier = new Verifier($secrets, $this->environment(), $tolerance > 0 ? $tolerance : Verifier::DEFAULT_TOLERANCE);
    }

    /** Deterministic Idempotency-Keys from `platform` + `store_uid`. */
    public function keys(): KeyFactory
    {
        if ($this->keys !== null) {
            return $this->keys;
        }
        $uid = $this->config['store_uid'] ?? null;
        $uid = is_string($uid) ? trim($uid) : '';
        if (strlen($uid) < 16) {
            throw NotConfiguredException::storeUid(strlen($uid));
        }
        $platform = $this->config['platform'] ?? 'laravel';

        return $this->keys = new KeyFactory(is_string($platform) && $platform !== '' ? $platform : 'laravel', $uid);
    }

    public function hasStoreUid(): bool
    {
        return $this->storeUidLength() >= 16;
    }

    /** Characters in the configured `store_uid` (0 when unset); at least 16 are required. */
    public function storeUidLength(): int
    {
        $uid = $this->config['store_uid'] ?? null;

        return is_string($uid) ? strlen(trim($uid)) : 0;
    }

    /**
     * The `dpay` config with every secret MASKED (`api_token`, `sandbox_token`,
     * `webhook.secret`, `webhook.previous_secret`) — safe for `dd()`, logs
     * and `about`. The real values reach only the SDK client and the verifier.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $copy = $this->config;
        foreach (['api_token', 'sandbox_token'] as $key) {
            if (isset($copy[$key]) && is_string($copy[$key]) && $copy[$key] !== '') {
                $copy[$key] = Config::mask(trim($copy[$key]));
            }
        }
        if (isset($copy['webhook']) && is_array($copy['webhook'])) {
            foreach (['secret', 'previous_secret'] as $key) {
                if (isset($copy['webhook'][$key]) && is_string($copy['webhook'][$key]) && $copy['webhook'][$key] !== '') {
                    $copy['webhook'][$key] = Config::mask(trim($copy['webhook'][$key]));
                }
            }
        }

        return $copy;
    }

    /**
     * The configured mode's token exactly as `.env` gave it (untrimmed), for
     * `dpay:doctor`'s whitespace check only — never log or echo it.
     */
    public function rawToken(): ?string
    {
        $token = $this->config[$this->mode() === 'live' ? 'api_token' : 'sandbox_token'] ?? null;

        return is_string($token) ? $token : null;
    }

    /** @return array<string, mixed> what var_dump / print_r / dd() show: secrets masked, no client */
    public function __debugInfo(): array
    {
        return ['config' => $this->config(), 'client' => $this->client === null ? null : $this->client::class];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('DPay\\Laravel\\DPayManager holds live credentials and is never serialized — inject the DPay facade inside the job instead of passing the manager. (يحمل مفاتيح حية ولا يُسلسل.)');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('DPay\\Laravel\\DPayManager is never serialized.');
    }

    /** @return array<string, mixed> the `webhook` block, secrets masked ({@see DPayManager::webhookSecrets()} for the real ones) */
    public function webhookConfig(): array
    {
        $out = $this->rawWebhookConfig();
        foreach (['secret', 'previous_secret'] as $key) {
            if (isset($out[$key]) && is_string($out[$key]) && $out[$key] !== '') {
                $out[$key] = Config::mask(trim($out[$key]));
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function rawWebhookConfig(): array
    {
        $webhook = $this->config['webhook'] ?? [];
        $out = [];
        foreach (is_array($webhook) ? $webhook : [] as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    private static function floatOr(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
