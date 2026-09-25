<?php

declare(strict_types=1);

namespace DPay\Laravel\Tests\Feature;

use DPay\Laravel\Events\CheckoutCompleted;
use DPay\Laravel\Events\PaymentPaid;
use DPay\Laravel\Events\WebhookReceived;
use DPay\Laravel\Events\WebhookTestReceived;
use DPay\Laravel\Http\Middleware\VerifyDPaySignature;
use DPay\Laravel\Jobs\HandleWebhook;
use DPay\Laravel\Tests\TestCase;
use DPay\Laravel\Webhooks\CacheDeduper;
use DPay\Laravel\Webhooks\WebhookProcessor;
use DPay\Webhooks\Signature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/**
 * The webhook contract as a merchant's Laravel app experiences it:
 * verification over the RAW bytes, refusals 400/401, environment gate,
 * duplicate suppression, typed events, queue mode.
 */
final class WebhookRouteTest extends TestCase
{
    #[Test]
    public function aSignedCheckoutCompletedIsVerifiedOverTheRawBytesAndDispatchedTyped(): void
    {
        Event::fake([WebhookReceived::class, CheckoutCompleted::class]);

        $response = $this->postWebhook(self::checkoutCompletedBody(false));

        $response->assertOk()->assertExactJson([
            'ok' => true,
            'event' => 'checkout.completed',
            'duplicate' => false,
            'test' => false,
            'reference' => '10483',
        ]);
        Event::assertDispatched(WebhookReceived::class, static fn (WebhookReceived $e): bool => $e->name() === 'checkout.completed');
        Event::assertDispatched(CheckoutCompleted::class, static function (CheckoutCompleted $e): bool {
            self::assertSame('cs_test_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', $e->checkoutSessionId());
            self::assertSame('10483', $e->reference());
            self::assertFalse($e->live());
            self::assertSame('125.5', (string) $e->amount());
            self::assertSame('LYD', $e->currency());
            self::assertSame('126.76', (string) $e->amountCharged());
            self::assertSame('txn_9f1', $e->txId());
            self::assertSame('edfali', $e->payMethod());
            self::assertSame('https://dpay.ly/receipt/812/abc', $e->receiptUrl());
            self::assertSame(['order_id' => 10483, 'platform' => 'laravel'], $e->metadata());
            self::assertSame('سالم', $e->payload()['customer']['name']);
            self::assertTrue($e->matchesOrder('125.50', 'LYD', '10483'));
            self::assertFalse($e->matchesOrder('125.51', 'LYD', '10483'));
            self::assertFalse($e->matchesOrder('125.50', 'USD'));
            self::assertFalse($e->matchesOrder('125.50', 'LYD', '99'));
            self::assertSame('sandbox:cs_test_01K5N2M3Q4R5S6T7V8W9X0Y1Z2:checkout.completed', $e->dedupeKey());

            return true;
        });
    }

    #[Test]
    public function thePublishedPhpSampleAndTheMiddlewareAgreeOnTheSignature(): void
    {
        // The website's VERIFY_SIGNATURE_PHP, verbatim: hash_hmac('sha256', $timestamp . '.' . $raw, $secret).
        $raw = self::paymentPaidBody(false);
        $ts = (string) time();
        $expected = hash_hmac('sha256', $ts.'.'.$raw, self::SECRET);
        self::assertSame($expected, Signature::compute($ts, $raw, self::SECRET));

        Event::fake([PaymentPaid::class]);
        $this->postWebhook($raw, $ts, null, ['HTTP_X_DPAY_SIGNATURE' => $expected])->assertOk();
        Event::assertDispatched(PaymentPaid::class, static function (PaymentPaid $e): bool {
            self::assertSame(124, $e->sessionId());
            self::assertSame('cs_test_01K5N2M3Q4R5S6T7V8W9X0Y1Z2', $e->checkoutSessionId());
            self::assertSame('10483', $e->reference());
            self::assertSame('دفع', $e->data()['note']);
            self::assertSame('moamalat', $e->payMethod());

            return true;
        });
    }

    #[Test]
    public function reEncodingTheBodyWouldBreakTheSignatureSoTheRawBytesAreUsed(): void
    {
        $raw = self::paymentPaidBody(false);
        $reEncoded = json_encode(json_decode($raw, true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertNotSame($raw, $reEncoded, 'the fixture has \\/ and \\uXXXX escapes that a re-encode loses');

        $this->fakeDPayEvents();
        $ts = (string) time();
        // Signed over the raw bytes → accepted even though the parsed array would re-encode differently.
        $this->postWebhook($raw, $ts)->assertOk();
        // Signed over the re-encoded bytes but sent raw → refused.
        $this->postWebhook($raw, $ts, null, ['HTTP_X_DPAY_SIGNATURE' => Signature::compute($ts, (string) $reEncoded, self::SECRET)])
            ->assertStatus(401)->assertJsonPath('error', 'invalid_signature');
    }

    #[Test]
    public function aBadSignatureIs401AndDispatchesNothing(): void
    {
        $this->fakeDPayEvents();
        $this->postWebhook(self::checkoutCompletedBody(false), null, 'whsec_'.str_repeat('9', 64))
            ->assertStatus(401)
            ->assertJson(['ok' => false, 'error' => 'invalid_signature']);
        $this->assertNoDPayEventDispatched();
    }

    #[Test]
    public function aStaleTimestampIs401(): void
    {
        $this->fakeDPayEvents();
        $this->postWebhook(self::checkoutCompletedBody(false), (string) (time() - 301))
            ->assertStatus(401)->assertJsonPath('error', 'stale_timestamp');
        $this->postWebhook(self::checkoutCompletedBody(false), (string) (time() + 301))
            ->assertStatus(401)->assertJsonPath('error', 'stale_timestamp');
        // Inside the window on either side.
        $this->postWebhook(self::checkoutCompletedBody(false), (string) (time() - 299))->assertOk();
        Event::assertDispatched(CheckoutCompleted::class, 1);
    }

    #[Test]
    public function missingHeadersAndMalformedBodiesAre400(): void
    {
        $this->fakeDPayEvents();
        $this->postWebhook(self::checkoutCompletedBody(false), null, null, ['HTTP_X_DPAY_TIMESTAMP' => ''])
            ->assertStatus(400)->assertJsonPath('error', 'missing_timestamp');
        $this->postWebhook(self::checkoutCompletedBody(false), null, null, ['HTTP_X_DPAY_SIGNATURE' => ''])
            ->assertStatus(400)->assertJsonPath('error', 'missing_signature');
        $this->postWebhook(self::checkoutCompletedBody(false), 'not-a-number')
            ->assertStatus(400)->assertJsonPath('error', 'stale_timestamp');
        $this->postWebhook('{"event":')->assertStatus(400)->assertJsonPath('error', 'invalid_body');
        $this->postWebhook('{"live":false}')->assertStatus(400)->assertJsonPath('error', 'invalid_body');
        $this->assertNoDPayEventDispatched();
    }

    #[Test]
    public function noSecretConfiguredIs400NeverASilent200(): void
    {
        $this->reconfigure(['dpay.webhook.secret' => null]);
        $this->postWebhook(self::checkoutCompletedBody(false))
            ->assertStatus(400)->assertJsonPath('error', 'not_configured');
    }

    #[Test]
    public function aLiveEventOnASandboxStoreIsAcknowledgedAndIgnoredByDefault(): void
    {
        $this->fakeDPayEvents();
        $this->postWebhook(self::checkoutCompletedBody(true))
            ->assertOk()->assertExactJson(['ok' => true, 'ignored' => 'environment', 'mode' => 'sandbox']);
        $this->assertNoDPayEventDispatched();
    }

    #[Test]
    public function environmentMismatchCanBeRejectedWith400(): void
    {
        $this->reconfigure(['dpay.webhook.environment_mismatch' => 'reject']);
        $this->fakeDPayEvents();
        $this->postWebhook(self::checkoutCompletedBody(true))
            ->assertStatus(400)->assertJsonPath('error', 'environment_mismatch');
        $this->assertNoDPayEventDispatched();
    }

    #[Test]
    public function aLiveStoreAcceptsLiveEventsOnly(): void
    {
        $this->reconfigure(['dpay.mode' => 'live', 'dpay.api_token' => self::LIVE_TOKEN]);
        Event::fake([CheckoutCompleted::class]);
        $this->postWebhook(self::checkoutCompletedBody(true, 'cs_01K5N2M3Q4R5S6T7V8W9X0Y1Z2'))->assertOk()->assertJsonPath('duplicate', false);
        $this->postWebhook(self::checkoutCompletedBody(false))->assertOk()->assertJsonPath('ignored', 'environment');
        Event::assertDispatched(CheckoutCompleted::class, 1);
    }

    #[Test]
    public function aDuplicateDeliveryIs200AndDispatchesNothingTwice(): void
    {
        Event::fake([CheckoutCompleted::class, WebhookReceived::class]);
        $first = (string) (time() - 5);
        $retry = (string) time(); // every retry is re-signed with a fresh timestamp over the same bytes
        $this->postWebhook(self::checkoutCompletedBody(false), $first)->assertOk()->assertJsonPath('duplicate', false);
        $this->postWebhook(self::checkoutCompletedBody(false), $retry)->assertOk()->assertJsonPath('duplicate', true);
        Event::assertDispatched(CheckoutCompleted::class, 1);
        Event::assertDispatched(WebhookReceived::class, 1);
        self::assertTrue(Cache::has(CacheDeduper::cacheKey('sandbox:cs_test_01K5N2M3Q4R5S6T7V8W9X0Y1Z2:checkout.completed')));
    }

    #[Test]
    public function aFailingListenerReleasesTheClaimAndAnswers500SoDPayRetries(): void
    {
        $calls = 0;
        Event::listen(CheckoutCompleted::class, static function () use (&$calls): void {
            $calls++;
            if ($calls === 1) {
                throw new \RuntimeException('database down');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postWebhook(self::checkoutCompletedBody(false));
            self::fail('the listener exception must surface as a non-2xx');
        } catch (\RuntimeException $e) {
            self::assertSame('database down', $e->getMessage());
        }
        // The retry is processed, not swallowed as a duplicate.
        $this->postWebhook(self::checkoutCompletedBody(false))->assertOk()->assertJsonPath('duplicate', false);
        self::assertSame(2, $calls);
    }

    #[Test]
    public function withTheExceptionHandlerAListenerFailureIsA500(): void
    {
        Event::listen(CheckoutCompleted::class, static function (): void {
            throw new \RuntimeException('database down');
        });
        $this->postWebhook(self::checkoutCompletedBody(false))->assertStatus(500);
    }

    #[Test]
    public function webhookTestIsVerifiedAcknowledgedAndNeverDeduped(): void
    {
        $this->fakeDPayEvents();
        $body = '{"event":"webhook.test","test":true,"merchant_id":7,"merchant_email":"m@example.com","webhook_id":3,"webhook_label":"Production","timestamp":"2026-09-21T10:00:00+00:00","message":"This is a test event from the DPAY dashboard. If you received this, your webhook is configured correctly."}';
        $this->postWebhook($body, null, null, ['HTTP_X_DPAY_EVENT' => 'webhook.test'])->assertOk()->assertJsonPath('test', true);
        $this->postWebhook($body, null, null, ['HTTP_X_DPAY_EVENT' => 'webhook.test'])->assertOk()->assertJsonPath('test', true)->assertJsonPath('duplicate', false);
        Event::assertDispatched(WebhookTestReceived::class, 2);
        Event::assertNotDispatched(WebhookReceived::class);
        Event::assertNotDispatched(PaymentPaid::class);
    }

    #[Test]
    public function anUnknownEventNameIsAcknowledgedWithOnlyTheGenericEvent(): void
    {
        $this->fakeDPayEvents();
        $this->postWebhook('{"event":"invoice.settled","live":false,"invoice_id":9}')
            ->assertOk()->assertJsonPath('event', 'invoice.settled');
        Event::assertDispatched(WebhookReceived::class, static fn (WebhookReceived $e): bool => $e->name() === 'invoice.settled' && $e->live() === false);
        Event::assertNotDispatched(CheckoutCompleted::class);
    }

    #[Test]
    public function aRotatedSecretKeepsThePreviousOneValidDuringTheGraceWindow(): void
    {
        $old = self::SECRET;
        $new = 'whsec_'.str_repeat('abcd', 16);
        $this->reconfigure(['dpay.webhook.secret' => $new, 'dpay.webhook.previous_secret' => $old]);
        $this->fakeDPayEvents();
        $this->postWebhook(self::checkoutCompletedBody(false), null, $new)->assertOk();
        $this->postWebhook(self::paymentPaidBody(false), null, $old)->assertOk();
        $this->postWebhook(self::paymentPaidBody(false), null, 'whsec_'.str_repeat('1', 64))->assertStatus(401);
    }

    #[Test]
    public function queueModeAnswersAtOnceAndQueuesTheVerifiedBody(): void
    {
        $this->reconfigure(['dpay.webhook.dispatch' => 'queue', 'dpay.webhook.queue' => 'dpay', 'dpay.webhook.connection' => 'sync']);
        Bus::fake();
        $this->fakeDPayEvents();
        $this->postWebhook(self::checkoutCompletedBody(false))->assertOk()->assertJsonPath('queued', true);
        Bus::assertDispatched(HandleWebhook::class, static function (HandleWebhook $job): bool {
            self::assertSame(self::checkoutCompletedBody(false), $job->rawBody);
            self::assertSame('dpay', $job->queue);
            self::assertSame('sync', $job->connection);

            return true;
        });
        Event::assertNotDispatched(CheckoutCompleted::class);

        // The worker side: the job dispatches the typed event and dedupes.
        $job = new HandleWebhook(self::checkoutCompletedBody(false), (string) time());
        $job->handle($this->app->make(WebhookProcessor::class));
        $job->handle($this->app->make(WebhookProcessor::class));
        Event::assertDispatched(CheckoutCompleted::class, 1);
    }

    #[Test]
    public function theWebhookRouteIsOutsideTheWebGroupSoNoCsrfTokenIsNeeded(): void
    {
        // A POST without any CSRF token succeeds; the signature is the only gate.
        $this->fakeDPayEvents();
        $this->postWebhook(self::checkoutCompletedBody(false))->assertOk();
    }

    #[Test]
    public function theMiddlewareProtectsAMerchantsOwnRoute(): void
    {
        $this->fakeDPayEvents();
        Route::post('my/hook', static fn (Request $r): array => ['name' => $r->attributes->get('dpay.event')->name])
            ->middleware(VerifyDPaySignature::class);
        $this->postWebhook(self::paymentPaidBody(false), null, null, [], '/my/hook')->assertOk()->assertJson(['name' => 'payment.paid']);
        $this->postWebhook(self::paymentPaidBody(false), null, 'whsec_'.str_repeat('1', 64), [], '/my/hook')->assertStatus(401);
    }
}
