<?php

declare(strict_types=1);

namespace DPay\Laravel\Tests;

use DPay\Laravel\DPayManager;
use DPay\Laravel\DPayServiceProvider;
use DPay\Laravel\Events\CheckoutReconciled;
use DPay\Laravel\Events\WebhookReceived;
use DPay\Laravel\Facades\DPay;
use DPay\Laravel\Webhooks\EventMap;
use DPay\Webhooks\Signature;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const SECRET = 'whsec_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    public const SANDBOX_TOKEN = 'sb_tk_0123456789abcdef0123456789abcdef';

    public const LIVE_TOKEN = '12|AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd0badf00d';

    public const STORE_UID = '0123456789abcdef0123456789abcdef';

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [DPayServiceProvider::class];
    }

    /** @return array<string, class-string> */
    protected function getPackageAliases($app): array
    {
        return ['DPay' => DPay::class];
    }

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $config = $app['config'];
        $config->set('app.url', 'https://shop.example.ly');
        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $config->set('cache.default', 'array');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $config->set('dpay.mode', 'sandbox');
        $config->set('dpay.sandbox_token', self::SANDBOX_TOKEN);
        $config->set('dpay.api_token', null);
        $config->set('dpay.store_uid', self::STORE_UID);
        $config->set('dpay.webhook.secret', self::SECRET);
        $config->set('dpay.webhook.previous_secret', null);
    }

    /** Fake only the package's events (framework events keep flowing). */
    protected function fakeDPayEvents(): void
    {
        Event::fake(self::dpayEventClasses());
    }

    protected function assertNoDPayEventDispatched(): void
    {
        foreach (self::dpayEventClasses() as $class) {
            Event::assertNotDispatched($class);
        }
    }

    /** @return list<class-string> */
    protected static function dpayEventClasses(): array
    {
        return array_values(array_unique(array_merge(array_values(EventMap::MAP), [WebhookReceived::class, CheckoutReconciled::class])));
    }

    /**
     * Change `dpay.*` config after boot and drop the cached manager/facade so the next call rebuilds it.
     *
     * @param  array<string, mixed>  $config
     */
    protected function reconfigure(array $config): void
    {
        foreach ($config as $key => $value) {
            config([$key => $value]);
        }
        $this->app->forgetInstance(DPayManager::class);
        DPay::clearResolvedInstances();
    }

    /**
     * POST a DPay-signed webhook with the RAW body exactly as given.
     *
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, string>  $serverOverrides
     */
    protected function postWebhook(array|string $payload, ?string $timestamp = null, ?string $secret = null, array $serverOverrides = [], string $path = '/dpay/webhook'): TestResponse
    {
        $raw = is_string($payload) ? $payload : (string) json_encode($payload);
        $ts = $timestamp ?? (string) time();
        $server = array_merge([
            'HTTP_X_DPAY_TIMESTAMP' => $ts,
            'HTTP_X_DPAY_EVENT' => 'payment.paid',
            'HTTP_X_DPAY_SIGNATURE' => Signature::compute($ts, $raw, $secret ?? self::SECRET),
            'HTTP_USER_AGENT' => 'DPAY-Webhooks/1.0',
            'CONTENT_TYPE' => 'application/json',
        ], $serverOverrides);
        foreach ($server as $k => $v) {
            if ($v === '') {
                unset($server[$k]);
            }
        }

        return $this->call('POST', $path, [], [], [], $server, $raw);
    }

    /** The platform's golden checkout.completed body (PHP json_encode bytes: `\/` escapes, `\uXXXX`). */
    protected static function checkoutCompletedBody(bool $live = false, string $id = 'cs_test_01K5N2M3Q4R5S6T7V8W9X0Y1Z2'): string
    {
        return '{"event":"checkout.completed","live":'.($live ? 'true' : 'false').',"checkout_session_id":"'.$id.'","reference":"10483","status":"paid","amount":125.5,"currency":"LYD","description":"Order #10483","metadata":{"order_id":10483,"platform":"laravel"},"customer":{"name":"سالم","email":null,"phone":"0912345678"},"payment":{"session_id":812,"pay_method":"edfali","tx_id":"txn_9f1","amount_charged":126.76,"fee_amount":1.255,"paid_at":"2026-09-21T10:04:12+00:00","receipt_url":"https:\/\/dpay.ly\/receipt\/812\/abc"},"created_at":"2026-09-21T10:00:00+00:00","occurred_at":"2026-09-21T10:04:12+00:00"}';
    }

    /** The platform's golden payment.paid body (webhook-signature.spec.ts). */
    protected static function paymentPaidBody(bool $live = true): string
    {
        return '{"event":"payment.paid","live":'.($live ? 'true' : 'false').',"session_id":124,"status":"paid","amount":10.71,"pay_method":"moamalat","tx_id":"txn_ab","system_reference":null,"network_reference":null,"paid_through":null,"payer_account":"6394****","data":{"url":"https:\/\/x.ly\/a","note":"دفع","checkout_session_id":"cs_test_01K5N2M3Q4R5S6T7V8W9X0Y1Z2","reference":"10483"},"created_at":"2026-08-29T10:00:00+00:00","paid_at":"2026-08-29T10:05:00+00:00"}';
    }
}
