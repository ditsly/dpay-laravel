<?php

declare(strict_types=1);

namespace DPay\Laravel\Tests\Feature;

use DPay\Laravel\Facades\DPay;
use DPay\Laravel\Tests\TestCase;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class DoctorCommandTest extends TestCase
{
    #[Test]
    public function aGoodConfigurationPassesInArabicAndEnglish(): void
    {
        $this->artisan('dpay:doctor')
            ->expectsOutputToContain('فحص DPay / DPay doctor')
            ->expectsOutputToContain('الوضع sandbox')
            ->expectsOutputToContain('Mode sandbox')
            ->expectsOutputToContain('DPAY_SANDBOX_TOKEN مضبوط')
            ->expectsOutputToContain('DPAY_SANDBOX_TOKEN is set (sandbox token, sb_tk_…cdef)')
            ->expectsOutputToContain('https://dpay.ly (https, DPay host)')
            ->expectsOutputToContain('DPAY_WEBHOOK_SECRET is set (whsec_…cdef)')
            ->expectsOutputToContain('Webhook route: POST https://shop.example.ly/dpay/webhook')
            ->expectsOutputToContain('DPAY_STORE_UID is set')
            ->expectsOutputToContain('اجتازت كل الفحوص.')
            ->expectsOutputToContain('All checks passed.')
            ->assertExitCode(0);
    }

    /** Guzzle 7 (Laravel 10–12) and Guzzle 8 (Laravel 13) are both named by their real major. */
    #[Test]
    public function theHttpClientLineNamesTheInstalledGuzzleMajor(): void
    {
        $major = (string) ClientInterface::MAJOR_VERSION;
        self::assertContains($major, ['7', '8']);
        $this->artisan('dpay:doctor --lang=en')
            ->expectsOutputToContain('HTTP client: Guzzle '.$major.' (GuzzleHttp\Client) (TLS verified, redirects refused).')
            ->assertExitCode(0);
    }

    #[Test]
    public function missingTokenSecretAndStoreUidFailWithTheEnvKeysToSet(): void
    {
        $this->reconfigure(['dpay.mode' => 'live', 'dpay.api_token' => null, 'dpay.webhook.secret' => null, 'dpay.store_uid' => null]);
        $this->artisan('dpay:doctor --lang=en')
            ->expectsOutputToContain('DPAY_API_TOKEN is missing')
            ->expectsOutputToContain('DPAY_WEBHOOK_SECRET is missing')
            ->expectsOutputToContain('DPAY_STORE_UID is missing — add this line to .env: DPAY_STORE_UID=')
            ->expectsOutputToContain('3 check(s) need attention.')
            ->doesntExpectOutputToContain('فحص')
            ->assertExitCode(1);
    }

    #[Test]
    public function aStoreUidThatIsSetButTooShortIsNamedAsSuchNotAsMissing(): void
    {
        $this->reconfigure(['dpay.store_uid' => 'shop-demo']);
        $this->artisan('dpay:doctor --lang=en')
            ->expectsOutputToContain('DPAY_STORE_UID is too short (9 characters; at least 16 random characters are needed) — replace it in .env with: DPAY_STORE_UID=')
            ->doesntExpectOutputToContain('DPAY_STORE_UID is missing')
            ->assertExitCode(1);
        $this->artisan('dpay:doctor --lang=ar')
            ->expectsOutputToContain('قيمة DPAY_STORE_UID قصيرة (9 أحرف')
            ->assertExitCode(1);
    }

    #[Test]
    public function aSandboxTokenInLiveModeIsNamed(): void
    {
        $this->reconfigure(['dpay.mode' => 'live', 'dpay.api_token' => self::SANDBOX_TOKEN]);
        $this->artisan('dpay:doctor --lang=ar')
            ->expectsOutputToContain('DPAY_API_TOKEN يحمل رمز sandbox بينما الوضع live')
            ->doesntExpectOutputToContain('holds a sandbox token')
            ->assertExitCode(1);
    }

    #[Test]
    public function warnsAboutLocalWebhookUrlsMalformedSecretsAndUnsharedCaches(): void
    {
        $this->reconfigure(['app.url' => 'http://localhost:8000', 'dpay.webhook.secret' => 'not-a-whsec', 'dpay.webhook.dedupe' => 'cache', 'cache.default' => 'array']);
        $this->artisan('dpay:doctor --lang=en')
            ->expectsOutputToContain('APP_URL is a local host')
            ->expectsOutputToContain('does not look like whsec_')
            ->expectsOutputToContain('cache store "array" is not shared between workers')
            ->assertExitCode(0);
    }

    #[Test]
    public function databaseDedupeNeedsTheTable(): void
    {
        $this->reconfigure(['dpay.webhook.dedupe' => 'database']);
        $this->artisan('dpay:doctor --lang=en')
            ->expectsOutputToContain('table "dpay_webhook_events" is missing — php artisan vendor:publish --tag=dpay-migrations')
            ->assertExitCode(1);

        $migration = require __DIR__.'/../../database/migrations/create_dpay_webhook_events_table.php.stub';
        $migration->up();
        $this->artisan('dpay:doctor --lang=en')->expectsOutputToContain('table "dpay_webhook_events" exists')->assertExitCode(0);
    }

    #[Test]
    public function onlineChecksUseTheApiAndListUsableMethods(): void
    {
        DPay::fake();
        $this->artisan('dpay:doctor --online')
            ->expectsOutputToContain('API reachable: https://dpay.ly/api/health → ok')
            ->expectsOutputToContain('الرمز مقبول')
            ->expectsOutputToContain('usable pay methods: edfali, mobicash, moamalat')
            ->assertExitCode(0);
    }

    #[Test]
    public function onlineAuthFailureIsReported(): void
    {
        DPay::fake()->respondWith('GET', '#/api/sandbox/pay-methods$#', 401, ['message' => 'Unauthenticated.', 'status' => 401]);
        $this->artisan('dpay:doctor --online --lang=en')
            ->expectsOutputToContain('The API refused the token: Unauthenticated.')
            ->assertExitCode(1);
    }

    #[Test]
    public function jsonOutputCarriesBothLanguages(): void
    {
        self::assertSame(0, Artisan::call('dpay:doctor', ['--json' => true]));
        $json = json_decode(trim(Artisan::output()), true);
        self::assertTrue($json['ok']);
        self::assertSame(0, $json['failures']);
        $mode = array_values(array_filter($json['checks'], static fn (array $c): bool => $c['key'] === 'mode_ok'))[0];
        self::assertSame('الوضع sandbox', $mode['ar']);
        self::assertSame('Mode sandbox', $mode['en']);
    }
}
