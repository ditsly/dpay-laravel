<?php

declare(strict_types=1);

namespace DPay\Laravel;

use DPay\Client;
use DPay\Laravel\Console\DoctorCommand;
use DPay\Laravel\Console\ReconcileCommand;
use DPay\Laravel\Webhooks\CacheDeduper;
use DPay\Laravel\Webhooks\DatabaseDeduper;
use DPay\Laravel\Webhooks\Deduper;
use DPay\Laravel\Webhooks\NullDeduper;
use DPay\Laravel\Webhooks\WebhookProcessor;
use DPay\Webhooks\Verifier;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Wires the package: config merge + publish, the {@see DPayManager}
 * singleton (behind the `DPay` facade), the SDK {@see Client} and
 * {@see Verifier} bindings, the webhook route, translations, the migration
 * stub and the `dpay:doctor` / `dpay:reconcile` commands.
 *
 * Auto-discovered (composer `extra.laravel`); nothing runs at boot that
 * needs a token, so `php artisan` works before .env is filled in.
 */
final class DPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dpay.php', 'dpay');

        $this->app->singleton(DPayManager::class, function (Application $app): DPayManager {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            /** @var array<string, mixed> $dpay */
            $dpay = $config->get('dpay', []);

            return new DPayManager($dpay, $this->sdkLogger($dpay));
        });
        $this->app->alias(DPayManager::class, 'dpay');

        $this->app->bind(Client::class, static fn (Application $app): Client => self::manager($app)->client());
        $this->app->bind(Verifier::class, static fn (Application $app): Verifier => self::manager($app)->verifier());

        $this->app->singleton(Deduper::class, function (Application $app): Deduper {
            $dpay = self::manager($app);
            $webhook = $dpay->webhookConfig();
            $driver = array_key_exists('dedupe', $webhook) ? $webhook['dedupe'] : 'cache';
            if ($driver === null || $driver === false || $driver === 'none') {
                return new NullDeduper();
            }
            if ($driver === 'database') {
                /** @var ConnectionResolverInterface $db */
                $db = $app->make(ConnectionResolverInterface::class);
                $table = $webhook['table'] ?? 'dpay_webhook_events';

                return new DatabaseDeduper($db->connection(), is_string($table) && $table !== '' ? $table : 'dpay_webhook_events');
            }
            /** @var CacheFactory $cache */
            $cache = $app->make(CacheFactory::class);
            $store = $webhook['cache_store'] ?? null;
            $ttl = $webhook['dedupe_ttl'] ?? 7 * 24 * 3600;

            return new CacheDeduper($cache->store(is_string($store) && $store !== '' ? $store : null), is_numeric($ttl) ? (int) $ttl : 7 * 24 * 3600);
        });

        $this->app->singleton(WebhookProcessor::class, static function (Application $app): WebhookProcessor {
            /** @var Dispatcher $events */
            $events = $app->make(Dispatcher::class);
            /** @var Deduper $deduper */
            $deduper = $app->make(Deduper::class);

            return new WebhookProcessor($events, $deduper);
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'dpay');

        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        if ((bool) $config->get('dpay.webhook.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/dpay.php' => $this->app->configPath('dpay.php')], 'dpay-config');
            $this->publishes([
                __DIR__.'/../database/migrations/create_dpay_webhook_events_table.php.stub' => $this->app->databasePath('migrations/'.date('Y_m_d_His').'_create_dpay_webhook_events_table.php'),
            ], 'dpay-migrations');
            $this->publishes([__DIR__.'/../resources/lang' => $this->app->langPath('vendor/dpay')], 'dpay-lang');
            $this->commands([DoctorCommand::class, ReconcileCommand::class]);
        }

        if (class_exists(AboutCommand::class)) {
            AboutCommand::add('DPay', static fn (): array => [
                'Mode' => self::configString($config, 'dpay.mode', 'sandbox'),
                'Base URL' => self::configString($config, 'dpay.base_url', 'https://dpay.ly'),
                'Webhook route' => (bool) $config->get('dpay.webhook.enabled', true) ? 'POST /'.trim(self::configString($config, 'dpay.webhook.path', 'dpay/webhook'), '/') : 'disabled',
                'Version' => Version::PACKAGE.' (sdk '.\DPay\Version::SDK.')',
            ]);
        }
    }

    private static function manager(Application $app): DPayManager
    {
        /** @var DPayManager $manager */
        $manager = $app->make(DPayManager::class);

        return $manager;
    }

    private static function configString(ConfigRepository $config, string $key, string $default): string
    {
        $value = $config->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @param array<string, mixed> $dpay */
    private function sdkLogger(array $dpay): ?LoggerInterface
    {
        $channel = $dpay['log_channel'] ?? null;
        if (! is_string($channel) || $channel === '') {
            return null;
        }
        if (! $this->app->bound(LogManager::class) && ! $this->app->bound('log')) {
            return null;
        }
        /** @var LogManager $log */
        $log = $this->app->make('log');

        return $log->channel($channel);
    }
}
