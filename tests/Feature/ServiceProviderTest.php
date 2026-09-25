<?php

declare(strict_types=1);

namespace DPay\Laravel\Tests\Feature;

use DPay\Client;
use DPay\Config\Environment;
use DPay\Exceptions\InvalidArgumentException;
use DPay\Laravel\DPayManager;
use DPay\Laravel\Exceptions\NotConfiguredException;
use DPay\Laravel\Facades\DPay;
use DPay\Laravel\Http\Controllers\WebhookController;
use DPay\Laravel\Http\Middleware\VerifyDPaySignature;
use DPay\Laravel\Tests\TestCase;
use DPay\Webhooks\Verifier;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;

final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function mergesTheConfigAndBindsTheSingleton(): void
    {
        self::assertSame('sandbox', config('dpay.mode'));
        self::assertSame('dpay/webhook', config('dpay.webhook.path'));
        self::assertSame(300, config('dpay.webhook.tolerance'));
        self::assertSame(30, config('dpay.reconcile.limit'));

        $manager = $this->app->make(DPayManager::class);
        self::assertSame($manager, $this->app->make('dpay'));
        self::assertSame($manager, DPay::getFacadeRoot());
        self::assertTrue(DPay::isSandbox());
        self::assertSame(Environment::Sandbox, DPay::environment());
    }

    #[Test]
    public function buildsOneSandboxClientFromTheSandboxToken(): void
    {
        $client = DPay::client();
        self::assertInstanceOf(Client::class, $client);
        self::assertSame(Environment::Sandbox, $client->environment());
        self::assertSame(self::SANDBOX_TOKEN, $client->config->token());
        self::assertSame('https://dpay.ly', $client->config->baseUrl);
        self::assertSame('ar', $client->config->locale);
        self::assertSame($client, DPay::client(), 'the client is built once');
        self::assertSame($client, $this->app->make(Client::class));
    }

    #[Test]
    public function liveModeUsesTheApiToken(): void
    {
        $this->reconfigure(['dpay.mode' => 'live', 'dpay.api_token' => self::LIVE_TOKEN, 'dpay.locale' => 'en']);

        $client = DPay::client();
        self::assertSame(Environment::Live, $client->environment());
        self::assertSame(self::LIVE_TOKEN, $client->config->token());
        self::assertSame('en', $client->config->locale);
        self::assertTrue(DPay::isLive());
    }

    #[Test]
    public function secretsNeverLeakThroughTheManagerOrTheClient(): void
    {
        $this->reconfigure(['dpay.mode' => 'live', 'dpay.api_token' => self::LIVE_TOKEN, 'dpay.webhook.previous_secret' => 'whsec_'.str_repeat('9', 64)]);
        $manager = DPay::getFacadeRoot();
        self::assertInstanceOf(DPayManager::class, $manager);
        $client = $manager->client();
        $secret = (string) config('dpay.webhook.secret');
        $previous = 'whsec_'.str_repeat('9', 64);

        // The masked config copy is what dd()/about/logs see; the real values still reach the SDK.
        $config = $manager->config();
        self::assertSame('12|AbC…f00d', $config['api_token'] ?? null);
        self::assertSame('whsec_…9999', $config['webhook']['previous_secret'] ?? null);
        self::assertSame([$secret, $previous], $manager->webhookSecrets());
        self::assertSame(self::LIVE_TOKEN, $manager->rawToken());
        self::assertSame(self::LIVE_TOKEN, $client->config->token());

        foreach ([self::LIVE_TOKEN, self::SANDBOX_TOKEN, $secret, $previous] as $value) {
            foreach (['json-manager' => (string) json_encode($manager), 'json-client' => (string) json_encode($client), 'print_r-manager' => print_r($manager, true), 'print_r-client' => print_r($client, true), 'config' => (string) json_encode($config), 'webhookConfig' => (string) json_encode($manager->webhookConfig())] as $label => $dump) {
                self::assertStringNotContainsString($value, $dump, "$label leaks a secret");
            }
        }
        foreach ([$manager, $client, $manager->verifier()] as $object) {
            try {
                serialize($object);
                self::fail($object::class.' must refuse serialize()');
            } catch (\LogicException $e) {
                self::assertStringContainsString('never serialized', $e->getMessage());
            }
        }
    }

    #[Test]
    public function theFacadeDocblockNamesEveryPublicManagerMethod(): void
    {
        $public = [];
        foreach ((new \ReflectionClass(DPayManager::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $method->isStatic() && ! str_starts_with($method->getName(), '__')) {
                $public[] = $method->getName();
            }
        }
        $doc = (string) (new \ReflectionClass(DPay::class))->getDocComment();
        preg_match_all('/@method static [^\n]*? (\w+)\(/', $doc, $m);
        $documented = $m[1];
        sort($public);
        sort($documented);
        self::assertSame([], array_values(array_diff($public, $documented)), 'DPayManager methods missing from the DPay facade @method block');
        self::assertSame(['fake'], array_values(array_diff($documented, $public)), 'only fake() is the facade\'s own');
    }

    #[Test]
    public function aMissingTokenIsABilingualNotConfiguredError(): void
    {
        $this->reconfigure(['dpay.mode' => 'live', 'dpay.api_token' => null]);

        try {
            DPay::client();
            self::fail('expected NotConfiguredException');
        } catch (NotConfiguredException $e) {
            self::assertStringContainsString('DPAY_API_TOKEN', $e->getMessage());
            self::assertStringContainsString('لم يُضبط', $e->getMessage());
            self::assertStringContainsString('No API token', $e->getMessage());
        }
    }

    #[Test]
    public function aSandboxTokenInLiveModeIsRefusedBeforeAnyRequest(): void
    {
        $this->reconfigure(['dpay.mode' => 'live', 'dpay.api_token' => self::SANDBOX_TOKEN]);

        $this->expectException(InvalidArgumentException::class);
        DPay::client();
    }

    #[Test]
    public function anInvalidModeIsRefused(): void
    {
        $this->reconfigure(['dpay.mode' => 'production']);

        $this->expectException(NotConfiguredException::class);
        DPay::mode();
    }

    #[Test]
    public function theBaseUrlPolicyRefusesNonDPayHostsUnlessOptedIn(): void
    {
        $this->reconfigure(['dpay.base_url' => 'https://evil.example']);
        try {
            $this->app->make(DPayManager::class)->client();
            self::fail('expected a refused host');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('not a DPay host', $e->getMessage());
        }

        $this->reconfigure(['dpay.base_url' => 'https://next.dpay.ly']);
        self::assertSame('https://next.dpay.ly', DPay::client()->config->baseUrl);

        $this->reconfigure(['dpay.base_url' => 'http://localhost:8080', 'dpay.allow_http_localhost' => true]);
        self::assertSame('http://localhost:8080', DPay::client()->config->baseUrl);

        $this->reconfigure(['dpay.base_url' => 'https://api.partner.ly', 'dpay.allowed_hosts' => ['api.partner.ly'], 'dpay.allow_http_localhost' => false]);
        self::assertSame('https://api.partner.ly', DPay::client()->config->baseUrl);
    }

    #[Test]
    public function theVerifierIsBuiltFromTheSecretsAndTheMode(): void
    {
        $verifier = $this->app->make(Verifier::class);
        self::assertSame($verifier, DPay::verifier());

        $this->reconfigure(['dpay.webhook.secret' => null]);
        $this->expectException(NotConfiguredException::class);
        DPay::verifier();
    }

    #[Test]
    public function theKeyFactoryNeedsAStoreUid(): void
    {
        self::assertSame(DPay::keys()->forCheckout(10483, 1), DPay::keys()->forCheckout('10483', 1));
        self::assertNotSame(DPay::keys()->forCheckout(10483, 1), DPay::keys()->forCheckout(10483, 2));

        $this->reconfigure(['dpay.store_uid' => 'short']);
        $this->expectException(NotConfiguredException::class);
        $this->expectExceptionMessage('DPAY_STORE_UID is too short (5 characters)');
        DPay::keys();
    }

    #[Test]
    public function registersTheWebhookRouteWithoutCsrfOrSession(): void
    {
        $route = Route::getRoutes()->getByName('dpay.webhook');
        self::assertNotNull($route);
        self::assertSame('dpay/webhook', $route->uri());
        self::assertSame(['POST'], $route->methods());
        self::assertSame(WebhookController::class, $route->getActionName());
        self::assertSame([VerifyDPaySignature::class], $route->gatherMiddleware());
    }

    #[Test]
    #[DefineEnvironment('useACustomWebhookPath')]
    public function theWebhookRouteHonoursPathAndExtraMiddleware(): void
    {
        $route = Route::getRoutes()->getByName('dpay.webhook');
        self::assertNotNull($route);
        self::assertSame('hooks/dpay', $route->uri());
        self::assertSame(['throttle:60,1', VerifyDPaySignature::class], $route->gatherMiddleware());
    }

    #[Test]
    #[DefineEnvironment('disableTheWebhookRoute')]
    public function theWebhookRouteCanBeDisabled(): void
    {
        self::assertNull(Route::getRoutes()->getByName('dpay.webhook'));
        $this->postWebhook(self::paymentPaidBody(false))->assertNotFound();
    }

    #[Test]
    public function publishesConfigMigrationAndTranslations(): void
    {
        $paths = ServiceProvider::pathsToPublish(null, 'dpay-config');
        self::assertNotEmpty($paths);
        self::assertStringEndsWith('config/dpay.php', (string) array_key_first($paths));

        $paths = ServiceProvider::pathsToPublish(null, 'dpay-migrations');
        self::assertStringEndsWith('_create_dpay_webhook_events_table.php', (string) reset($paths));

        self::assertSame('فحص DPay', trans('dpay::dpay.doctor.title', [], 'ar'));
        self::assertSame('DPay doctor', trans('dpay::dpay.doctor.title', [], 'en'));
    }

    /** @param Application $app */
    protected function useACustomWebhookPath($app): void
    {
        $app['config']->set('dpay.webhook.path', '/hooks/dpay/');
        $app['config']->set('dpay.webhook.middleware', ['throttle:60,1']);
    }

    /** @param Application $app */
    protected function disableTheWebhookRoute($app): void
    {
        $app['config']->set('dpay.webhook.enabled', false);
    }
}
