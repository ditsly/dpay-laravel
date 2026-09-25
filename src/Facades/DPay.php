<?php

declare(strict_types=1);

namespace DPay\Laravel\Facades;

use DPay\Laravel\DPayManager;
use DPay\Laravel\Testing\DPayFake;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;

/**
 * Every public method of {@see DPayManager}, for IDE completion and Larastan
 * (tests/Feature/ServiceProviderTest keeps this block in sync).
 *
 * @method static \DPay\Laravel\CheckoutBuilder checkout()
 * @method static \DPay\Resources\CheckoutSessions checkoutSessions()
 * @method static \DPay\Resources\PayMethods payMethods()
 * @method static \DPay\Resources\PaymentSessions paymentSessions()
 * @method static \DPay\Resources\Payments payments()
 * @method static \DPay\Client client() the SDK client (throws NotConfiguredException / InvalidArgumentException on a bad config)
 * @method static \DPay\Webhooks\Verifier verifier()
 * @method static \DPay\Idempotency\KeyFactory keys()
 * @method static \DPay\Laravel\Reconciler reconciler()
 * @method static \DPay\Config\Environment environment()
 * @method static string mode()
 * @method static bool isLive()
 * @method static bool isSandbox()
 * @method static string|null token() the configured mode's token (trimmed), or null when unset — never log it
 * @method static string|null rawToken() the token exactly as .env gave it (dpay:doctor's whitespace check only)
 * @method static string tokenEnvKey()
 * @method static bool hasStoreUid()
 * @method static int storeUidLength()
 * @method static \DPay\Http\BaseUrlPolicy baseUrlPolicy()
 * @method static string baseUrl()
 * @method static string locale()
 * @method static array{class: string, hardened: bool|null} httpClient()
 * @method static list<string> webhookSecrets()
 * @method static array<string, mixed> webhookConfig() the `webhook` block, secrets masked
 * @method static array<string, mixed> config() the `dpay` config, secrets masked
 * @method static \DPay\Laravel\Testing\DPayFake fake(array<string, mixed> $config = [])
 *
 * @see DPayManager
 */
final class DPay extends Facade
{
    /**
     * Swap the manager for a fake whose API answers are canned: every SDK
     * call runs for real through a recording transport, so what your code
     * sends is asserted byte for byte and nothing reaches the network.
     *
     * @param  array<string, mixed>  $config  overrides of the `dpay` config (mode, store_uid, …)
     */
    public static function fake(array $config = []): DPayFake
    {
        $app = self::getFacadeApplication();
        if (! $app instanceof Application) {
            throw new \LogicException('DPay::fake() needs a booted Laravel application.');
        }
        /** @var ConfigRepository $repository */
        $repository = $app->make(ConfigRepository::class);
        $base = [];
        $current = $repository->get('dpay', []);
        foreach (is_array($current) ? $current : [] as $key => $value) {
            $base[(string) $key] = $value;
        }
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive($base, $config);
        $fake = new DPayFake($merged);
        $app->instance(DPayManager::class, $fake);
        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return DPayManager::class;
    }
}
