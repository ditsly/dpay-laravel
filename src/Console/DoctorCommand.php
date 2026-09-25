<?php

declare(strict_types=1);

namespace DPay\Laravel\Console;

use DPay\Config\Config;
use DPay\Config\Environment;
use DPay\Exceptions\AuthenticationException;
use DPay\Exceptions\DPayException;
use DPay\Idempotency\KeyFactory;
use DPay\Laravel\DPayManager;
use DPay\Laravel\Exceptions\NotConfiguredException;
use DPay\Webhooks\Signature;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Routing\Router;

/**
 * `php artisan dpay:doctor [--online] [--lang=both|ar|en] [--json]`
 *
 * Validates the configuration a merchant most often gets wrong — mode vs
 * token kind, the webhook secret, an https public webhook URL, the dedupe
 * store, the store uid — and, with `--online`, calls the API: health, the
 * token, and the pay methods that are actually usable. Arabic first, then
 * English, on every line.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'dpay:doctor {--online : Call the API (health, token, pay methods)} {--lang=both : both | ar | en} {--json : Machine-readable output}';

    protected $description = 'فحص إعدادات DPay / Check the DPay configuration (tokens, webhook, dedupe, store uid; --online calls the API).';

    /** @var list<array{level: string, key: string, params: array<string, string>}> */
    private array $results = [];

    public function handle(
        DPayManager $dpay,
        Translator $translator,
        ConfigRepository $config,
        Router $router,
        UrlGenerator $url,
        ConnectionResolverInterface $db,
    ): int {
        $this->results = [];
        $lang = $this->option('lang');
        $lang = is_string($lang) ? $lang : 'both';
        if (! in_array($lang, ['both', 'ar', 'en'], true)) {
            $lang = 'both';
        }

        $this->checkMode($dpay);
        $this->checkToken($dpay);
        $this->checkBaseUrl($dpay);
        $this->checkWebhookSecret($dpay);
        $this->checkWebhookRoute($dpay, $config, $router, $url);
        $this->checkDedupe($dpay, $config, $db);
        $this->checkStoreUid($dpay);
        $this->checkHttpClient($dpay);
        if ((bool) $this->option('online')) {
            $this->checkOnline($dpay);
        } else {
            $this->add('info', 'online_skipped');
        }

        $failures = count(array_filter($this->results, static fn (array $r): bool => $r['level'] === 'fail'));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'ok' => $failures === 0,
                'failures' => $failures,
                'checks' => array_map(fn (array $r): array => [
                    'level' => $r['level'],
                    'key' => $r['key'],
                    'ar' => $this->text($translator, $r, 'ar'),
                    'en' => $this->text($translator, $r, 'en'),
                ], $this->results),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $failures === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->line('');
        $this->line($this->title($translator, $lang));
        $this->line(str_repeat('─', 60));
        foreach ($this->results as $r) {
            $mark = match ($r['level']) {
                'fail' => '<fg=red>[✗]</>',
                'warn' => '<fg=yellow>[!]</>',
                'ok' => '<fg=green>[✓]</>',
                default => '<fg=gray>[·]</>',
            };
            if ($lang !== 'en') {
                $this->line($mark.' '.$this->text($translator, $r, 'ar'));
            }
            if ($lang !== 'ar') {
                $this->line(($lang === 'both' ? '    ' : $mark.' ').$this->text($translator, $r, 'en'));
            }
        }
        $this->line(str_repeat('─', 60));
        $summary = $failures === 0 ? ['level' => 'ok', 'key' => 'summary_ok', 'params' => []] : ['level' => 'fail', 'key' => 'summary_fail', 'params' => ['count' => (string) $failures]];
        if ($lang !== 'en') {
            $this->line($this->text($translator, $summary, 'ar'));
        }
        if ($lang !== 'ar') {
            $this->line($this->text($translator, $summary, 'en'));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkMode(DPayManager $dpay): void
    {
        try {
            $this->add('ok', 'mode_ok', ['mode' => $dpay->mode()]);
        } catch (NotConfiguredException) {
            $raw = $dpay->config()['mode'] ?? '';
            $this->add('fail', 'mode_bad', ['mode' => is_scalar($raw) ? (string) $raw : '']);
        }
    }

    private function checkToken(DPayManager $dpay): void
    {
        try {
            $mode = $dpay->mode();
        } catch (NotConfiguredException) {
            return;
        }
        $key = $dpay->tokenEnvKey();
        $raw = $dpay->rawToken() ?? '';
        if (trim($raw) === '') {
            $this->add('fail', 'token_missing', ['key' => $key]);

            return;
        }
        if (preg_match('/\s/', trim($raw)) === 1) {
            $this->add('fail', 'token_whitespace', ['key' => $key]);

            return;
        }
        $token = trim($raw);
        $actual = Environment::fromToken($token);
        if ($actual !== $dpay->environment()) {
            $this->add('fail', 'token_wrong_kind', ['key' => $key, 'actual' => $actual->value, 'mode' => $mode]);

            return;
        }
        $this->add('ok', 'token_ok', ['key' => $key, 'kind' => $actual->value, 'masked' => self::mask($token)]);
    }

    private function checkBaseUrl(DPayManager $dpay): void
    {
        try {
            $this->add('ok', 'base_url_ok', ['url' => $dpay->baseUrlPolicy()->normalize($dpay->baseUrl())]);
        } catch (DPayException $e) {
            $this->add('fail', 'base_url_bad', ['reason' => $e->getMessage()]);
        }
    }

    private function checkWebhookSecret(DPayManager $dpay): void
    {
        $secrets = $dpay->webhookSecrets();
        if ($secrets === []) {
            $this->add('fail', 'secret_missing');

            return;
        }
        if (! Signature::isWellFormedSecret($secrets[0])) {
            $this->add('warn', 'secret_malformed');
        } else {
            $this->add('ok', 'secret_ok', ['tail' => substr($secrets[0], -4)]);
        }
        if (count($secrets) > 1) {
            $this->add('info', 'previous_secret');
        }
    }

    private function checkWebhookRoute(DPayManager $dpay, ConfigRepository $config, Router $router, UrlGenerator $url): void
    {
        $webhook = $dpay->webhookConfig();
        if (! (bool) ($webhook['enabled'] ?? true)) {
            $this->add('warn', 'webhook_disabled');

            return;
        }
        $route = $router->getRoutes()->getByName('dpay.webhook');
        $path = $route !== null ? $route->uri() : (is_string($webhook['path'] ?? null) ? trim((string) $webhook['path'], '/') : 'dpay/webhook');
        // APP_URL, not the current request: this is the public address DPay will call.
        $appUrl = $config->get('app.url');
        $full = is_string($appUrl) && $appUrl !== '' ? rtrim($appUrl, '/').'/'.$path : $url->to($path);
        $host = strtolower((string) parse_url($full, PHP_URL_HOST));
        $scheme = strtolower((string) parse_url($full, PHP_URL_SCHEME));
        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.local')) {
            $this->add('warn', 'webhook_route_local', ['url' => $full]);
        } elseif ($scheme !== 'https') {
            $this->add('warn', 'webhook_route_http', ['url' => $full]);
        } else {
            $this->add('ok', 'webhook_route_ok', ['url' => $full]);
        }
        $policy = $webhook['environment_mismatch'] ?? null;
        $this->add('info', 'mismatch', ['policy' => is_string($policy) ? $policy : 'ignore']);
    }

    private function checkDedupe(DPayManager $dpay, ConfigRepository $config, ConnectionResolverInterface $db): void
    {
        $webhook = $dpay->webhookConfig();
        $driver = array_key_exists('dedupe', $webhook) ? $webhook['dedupe'] : 'cache';
        if ($driver === null || $driver === false || $driver === 'none') {
            $this->add('warn', 'dedupe_none');

            return;
        }
        if ($driver === 'database') {
            $table = $webhook['table'] ?? null;
            $table = is_string($table) && $table !== '' ? $table : 'dpay_webhook_events';
            try {
                $connection = $db->connection();
                $exists = $connection instanceof Connection && $connection->getSchemaBuilder()->hasTable($table);
            } catch (\Throwable) {
                $exists = false;
            }
            $this->add($exists ? 'ok' : 'fail', $exists ? 'dedupe_database_ok' : 'dedupe_database_missing', ['table' => $table]);

            return;
        }
        $store = $webhook['cache_store'] ?? null;
        if (! is_string($store) || $store === '') {
            $default = $config->get('cache.default', 'file');
            $store = is_string($default) ? $default : 'file';
        }
        $driverName = $config->get('cache.stores.'.$store.'.driver', $store);
        $driverName = is_string($driverName) ? $driverName : $store;
        $shared = in_array($driverName, ['redis', 'memcached', 'database', 'dynamodb', 'apc', 'octane'], true);
        $this->add($shared ? 'ok' : 'warn', $shared ? 'dedupe_cache' : 'dedupe_cache_weak', ['store' => $store]);
    }

    private function checkStoreUid(DPayManager $dpay): void
    {
        if ($dpay->hasStoreUid()) {
            $this->add('ok', 'store_uid_ok');
        } else {
            $this->add('fail', 'store_uid_missing', ['uid' => KeyFactory::generateStoreUid()]);
        }
    }

    private function checkHttpClient(DPayManager $dpay): void
    {
        $http = $dpay->httpClient();
        $client = $http['class'];
        if ($client === Client::class) {
            $client = 'Guzzle '.(defined('\GuzzleHttp\ClientInterface::MAJOR_VERSION') ? (string) ClientInterface::MAJOR_VERSION : '7').' ('.Client::class.')';
        }
        match ($http['hardened']) {
            true => $this->add('ok', 'http_client', ['client' => $client]),
            null => $this->add('warn', 'http_client_unverified', ['client' => $client]),
            false => $this->add('fail', 'http_client_missing', ['client' => $client]),
        };
    }

    private function checkOnline(DPayManager $dpay): void
    {
        try {
            $client = $dpay->client();
        } catch (NotConfiguredException $e) {
            $this->add('fail', 'auth_fail', ['reason' => $e->getMessage()]);

            return;
        }
        try {
            $health = $client->health();
            $status = $health['status'] ?? null;
            $this->add('ok', 'health_ok', ['url' => $dpay->baseUrl(), 'status' => is_scalar($status) ? (string) $status : 'ok']);
        } catch (DPayException $e) {
            $this->add('fail', 'health_fail', ['reason' => $e->getMessage()]);

            return;
        }
        try {
            $usable = $client->payMethods()->usable();
            if ($usable === []) {
                $this->add('warn', 'auth_none');
            } else {
                $this->add('ok', 'auth_ok', ['count' => (string) count($usable), 'methods' => implode(', ', array_map(static fn ($m): string => $m->slug, $usable))]);
            }
        } catch (AuthenticationException $e) {
            $this->add('fail', 'auth_fail', ['reason' => $e->getMessage()]);
        } catch (DPayException $e) {
            $this->add('fail', 'auth_fail', ['reason' => $e->getMessage()]);
        }
    }

    /** @param array<string, string> $params */
    private function add(string $level, string $key, array $params = []): void
    {
        $this->results[] = ['level' => $level, 'key' => $key, 'params' => $params];
    }

    /** @param array{level: string, key: string, params: array<string, string>} $r */
    private function text(Translator $translator, array $r, string $locale): string
    {
        $text = $translator->get('dpay::dpay.doctor.'.$r['key'], $r['params'], $locale);

        return is_string($text) ? $text : $r['key'];
    }

    private function title(Translator $translator, string $lang): string
    {
        $ar = $translator->get('dpay::dpay.doctor.title', [], 'ar');
        $en = $translator->get('dpay::dpay.doctor.title', [], 'en');
        $ar = is_string($ar) ? $ar : 'DPay';
        $en = is_string($en) ? $en : 'DPay';

        return match ($lang) {
            'ar' => $ar,
            'en' => $en,
            default => $ar.' / '.$en,
        };
    }

    private static function mask(string $token): string
    {
        return Config::mask($token);
    }
}
