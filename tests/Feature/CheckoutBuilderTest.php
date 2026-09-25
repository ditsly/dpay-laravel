<?php

declare(strict_types=1);

namespace DPay\Laravel\Tests\Feature;

use DPay\Exceptions\CheckoutNotOpenException;
use DPay\Exceptions\IdempotencyException;
use DPay\Exceptions\InvalidArgumentException;
use DPay\Exceptions\NotFoundException;
use DPay\Exceptions\RateLimitException;
use DPay\Exceptions\ValidationException;
use DPay\Laravel\CreatedCheckout;
use DPay\Laravel\DPayManager;
use DPay\Laravel\Events\CheckoutCompleted;
use DPay\Laravel\Facades\DPay;
use DPay\Laravel\Testing\DPayFake;
use DPay\Laravel\Testing\RecordedRequest;
use DPay\Laravel\Tests\TestCase;
use DPay\Models\CheckoutStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;

/** `DPay::checkout()` end to end against the fake API, and `DPay::fake()` itself. */
final class CheckoutBuilderTest extends TestCase
{
    #[Test]
    public function fakeSwapsTheSingletonAndTheFacade(): void
    {
        $fake = DPay::fake();
        self::assertInstanceOf(DPayFake::class, $fake);
        self::assertSame($fake, DPay::getFacadeRoot());
        self::assertSame($fake, $this->app->make(DPayManager::class));
        self::assertSame($fake, $this->app->make('dpay'));
        self::assertTrue(DPay::isSandbox());
        $fake->assertNothingSent();
    }

    #[Test]
    public function createsAHostedCheckoutWithTheExactBodyAndADeterministicKey(): void
    {
        $fake = DPay::fake();

        $created = DPay::checkout()
            ->amount('125.50')
            ->forOrder(10483, 1)
            ->description('Order #10483')
            ->returnUrl('https://shop.example.ly/dpay/return?order=10483&key=k9')
            ->cancelUrl('https://shop.example.ly/cart')
            ->metadata(['order_id' => 10483, 'platform' => 'laravel'])
            ->customer(name: 'سالم علي', phone: '0912345678')
            ->expiresIn(30)
            ->create();

        self::assertInstanceOf(CreatedCheckout::class, $created);
        self::assertStringStartsWith('cs_test_', $created->id);
        self::assertSame('https://dpay.ly/sandbox/pay/'.$created->id, $created->url);
        self::assertFalse($created->replayed);
        self::assertNotNull($created->expiresAt);
        self::assertTrue($created->session->isOpen());
        self::assertSame('125.5', (string) $created->session->amount);
        self::assertSame('10483', $created->session->reference);
        self::assertFalse($created->session->live);

        $fake->assertCheckoutCreatedCount(1)->assertIdempotencyKeysSent();
        $fake->assertCheckoutCreated(static function (RecordedRequest $r): bool {
            self::assertSame('https://dpay.ly/api/v2/checkout-sessions', $r->url);
            self::assertSame('Bearer '.self::SANDBOX_TOKEN, $r->header('Authorization'));
            self::assertSame('ar', $r->header('Accept-Language'));
            self::assertSame([
                'amount' => '125.50',
                'currency' => 'LYD',
                'return_url' => 'https://shop.example.ly/dpay/return?order=10483&key=k9',
                'reference' => '10483',
                'description' => 'Order #10483',
                'cancel_url' => 'https://shop.example.ly/cart',
                'metadata' => ['order_id' => 10483, 'platform' => 'laravel'],
                'customer' => ['name' => 'سالم علي', 'phone' => '0912345678'],
                'expires_in_minutes' => 30,
                'locale' => 'ar',
            ], $r->json);
            self::assertSame(DPay::keys()->forCheckout(10483, 1), $r->idempotencyKey());
            self::assertSame(43, strlen((string) $r->idempotencyKey()));

            return true;
        });

        // The redirect is a 303 to the hosted page, also when returned from a controller.
        $redirect = $created->redirect();
        self::assertInstanceOf(RedirectResponse::class, $redirect);
        self::assertSame(303, $redirect->getStatusCode());
        self::assertSame($created->url, $redirect->getTargetUrl());

        Route::post('pay', static fn (): CreatedCheckout => $created);
        $this->post('/pay')->assertStatus(303)->assertRedirect($created->url);
    }

    #[Test]
    public function aDoubleClickReplaysTheSameCheckout(): void
    {
        $fake = DPay::fake();
        $builder = static fn () => DPay::checkout()->amount('40')->forOrder('A-77')->returnUrl('https://shop.example.ly/r');

        $first = $builder()->create();
        $again = $builder()->create();
        self::assertSame($first->id, $again->id);
        self::assertSame($first->url, $again->url);
        self::assertTrue($again->replayed);
        $fake->assertCheckoutCreatedCount(2);
        self::assertCount(1, $fake->api->sessions());

        // A new attempt is a new checkout; the same key with another amount is the 409.
        $second = DPay::checkout()->amount('40')->forOrder('A-77', 2)->returnUrl('https://shop.example.ly/r')->create();
        self::assertNotSame($first->id, $second->id);
        $this->expectException(IdempotencyException::class);
        DPay::checkout()->amount('41')->forOrder('A-77')->returnUrl('https://shop.example.ly/r')->create();
    }

    #[Test]
    public function anExplicitKeyOrNoKeyIsHonoured(): void
    {
        $fake = DPay::fake();
        DPay::checkout()->amount('9.99')->returnUrl('https://shop.example.ly/r')->idempotencyKey('my-key-1')->create();
        DPay::checkout()->amount('9.99')->returnUrl('https://shop.example.ly/r')->create();
        $keys = array_map(static fn (RecordedRequest $r): ?string => $r->idempotencyKey(), $fake->checkoutCreations());
        self::assertSame(['my-key-1', null], $keys);
    }

    #[Test]
    public function clientSideValidationRefusesBeforeAnyRequest(): void
    {
        $fake = DPay::fake();
        foreach ([
            static fn () => DPay::checkout()->returnUrl('https://shop.example.ly/r')->create(),
            static fn () => DPay::checkout()->amount('10')->create(),
            static fn () => DPay::checkout()->amount('10.123')->returnUrl('https://shop.example.ly/r')->create(),
            static fn () => DPay::checkout()->amount('10')->returnUrl('http://shop.example.ly/r')->create(),
            static fn () => DPay::checkout()->amount('10')->returnUrl('https://shop.example.ly/r')->expiresIn(3)->create(),
            static fn () => DPay::checkout()->amount('10')->returnUrl('https://shop.example.ly/r')->locale('fr')->create(),
            static fn () => DPay::checkout()->amount('10')->returnUrl('https://shop.example.ly/r')->allowedMethods(['paypal'])->create(),
        ] as $call) {
            try {
                $call();
                self::fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
            }
        }
        $fake->assertNothingSent();
    }

    #[Test]
    public function theStatusReadIsTheTruthAndTheFakeCanMoveIt(): void
    {
        $fake = DPay::fake();
        $created = DPay::checkout()->amount('125.50')->forOrder(10483)->returnUrl('https://shop.example.ly/r')->create();

        self::assertSame(CheckoutStatus::Open, DPay::checkoutSessions()->get($created->id)->status);

        $fake->markPaid($created->id, ['pay_method' => 'moamalat', 'tx_id' => 'txn_777']);
        $paid = DPay::checkoutSessions()->get($created->id);
        self::assertTrue($paid->isPaid());
        self::assertNotNull($paid->payment);
        self::assertSame('moamalat', $paid->payment->payMethod);
        self::assertSame('txn_777', $paid->payment->txId);
        self::assertSame('126.76', $paid->payment->amountCharged->format(2));
        self::assertCount(1, $paid->attempts);
        self::assertTrue($paid->matchesOrder('125.50', 'LYD', '10483'));
        $fake->assertCheckoutRead($created->id);

        $this->expectException(CheckoutNotOpenException::class);
        DPay::checkoutSessions()->cancel($created->id);
    }

    #[Test]
    public function cancelAndListAndNotFound(): void
    {
        $fake = DPay::fake();
        $a = DPay::checkout()->amount('10')->forOrder(1)->returnUrl('https://shop.example.ly/r')->create();
        $b = DPay::checkout()->amount('20')->forOrder(2)->returnUrl('https://shop.example.ly/r')->create();

        self::assertSame(CheckoutStatus::Cancelled, DPay::checkoutSessions()->cancel($a->id)->status);
        $fake->assertCheckoutCancelled($a->id);

        $open = DPay::checkoutSessions()->list(CheckoutStatus::Open);
        self::assertSame([$b->id], array_map(static fn ($s) => $s->id, $open->data));
        self::assertFalse($open->hasMore);

        $this->expectException(NotFoundException::class);
        DPay::checkoutSessions()->get('cs_test_00000000000000000000000000');
    }

    #[Test]
    public function cannedAnswersLetATestExerciseFailures(): void
    {
        $fake = DPay::fake();
        $fake->respondWith('POST', '#/api/v2/checkout-sessions$#', 429, ['type' => '/api/v2/problems/too-many-requests', 'title' => 'Too Many Requests', 'status' => 429, 'detail' => 'Rate limit exceeded.'], ['Retry-After' => '30']);
        try {
            DPay::checkout()->amount('10')->returnUrl('https://shop.example.ly/r')->create();
            self::fail('expected RateLimitException');
        } catch (RateLimitException $e) {
            self::assertSame(30, $e->retryAfter);
        }

        $fake->respondWith('POST', '#/api/v2/checkout-sessions$#', 422, ['type' => '/api/v2/problems/validation', 'title' => 'Unprocessable Entity', 'status' => 422, 'detail' => 'Validation failed.', 'errors' => ['amount' => ['must be ≤ 2 dp']]]);
        try {
            DPay::checkout()->amount('10')->returnUrl('https://shop.example.ly/r')->create();
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['amount' => ['must be ≤ 2 dp']], $e->errors);
            self::assertSame('must be ≤ 2 dp', $e->first('amount'));
        }
        // Once consumed, the built-in handler answers again.
        self::assertTrue(DPay::checkout()->amount('10')->returnUrl('https://shop.example.ly/r')->create()->session->isOpen());
    }

    #[Test]
    public function payMethodsAndHealthAreServedInBothShapes(): void
    {
        DPay::fake();
        $usable = DPay::payMethods()->usable();
        self::assertNotEmpty($usable);
        self::assertNotContains('sadad', array_map(static fn ($m) => $m->slug, $usable), 'sadad is hidden in the sandbox');
        self::assertSame('ok', DPay::client()->health()['status']);

        DPay::fake(['mode' => 'live']);
        self::assertTrue(DPay::isLive());
        self::assertContains('sadad', array_map(static fn ($m) => $m->slug, DPay::payMethods()->usable()));
        $created = DPay::checkout()->amount('10')->returnUrl('https://shop.example.ly/r')->create();
        self::assertStringStartsWith('cs_', $created->id);
        self::assertStringStartsNotWith('cs_test_', $created->id);
        self::assertSame('https://dpay.ly/pay/'.$created->id, $created->url);
    }

    #[Test]
    public function signedWebhookHelperProducesADeliveryTheRouteAccepts(): void
    {
        $fake = DPay::fake();
        $created = DPay::checkout()->amount('125.50')->forOrder(10483)->returnUrl('https://shop.example.ly/r')->create();
        $fake->markPaid($created->id);
        [$raw, $server] = $fake->signedWebhook($fake->checkoutCompletedPayload($created->id));

        $this->fakeDPayEvents();
        $this->call('POST', '/dpay/webhook', [], [], [], $server, $raw)->assertOk()->assertJsonPath('reference', '10483');
        Event::assertDispatched(CheckoutCompleted::class, static fn ($e): bool => $e->checkoutSessionId() === $created->id && $e->matchesOrder('125.50', 'LYD', '10483'));
    }

    #[Test]
    public function seededCheckoutsSupportReturnHandlerTests(): void
    {
        $fake = DPay::fake();
        $id = $fake->seedCheckout('99.90', 'ORD-5', 'paid');
        $fake->markPaid($id);
        $checkout = DPay::checkoutSessions()->get($id);
        self::assertTrue($checkout->isPaid());
        self::assertTrue($checkout->matchesOrder('99.9', 'LYD', 'ORD-5'));
        self::assertSame($id, $fake->lastCheckoutId());
    }
}
