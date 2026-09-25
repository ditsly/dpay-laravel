# DPay for Laravel — `dpay/laravel`

<div dir="rtl">

حزمة DPay الرسمية لـ Laravel 10 و11 و12 — صفحة دفع مستضافة بنداء واحد، مسار webhook موقّع مع أحداث مُنمّطة، أمر `dpay:doctor` ثنائي اللغة، و`DPay::fake()` لاختباراتك. مبنية فوق [`dpay/dpay-php`](../php-sdk).

## ما الذي تفعله الحزمة

- **الدفع المستضاف أولًا.** تنشئ جلسة دفع (`checkout session`) بمبلغ عشري بالدينار، تُحوّل الزبون إلى صفحة DPay، وتستقبل النتيجة. لا أرقام هواتف ولا أرقام بطاقات ولا رموز OTP تمرّ عبر خادمك، ولا تصطدم بحدّ التحقق (5 في الدقيقة لكل IP).
- **Webhook موقّع.** المسار `POST /dpay/webhook` يتحقق من التوقيع على البايتات الخام، ويرفض الطوابع الزمنية القديمة (300 ثانية)، ويتأكد من أن `live` يطابق وضعك، ويمنع التكرار، ثم يطلق أحداثًا مُنمّطة (`CheckoutCompleted`، `PaymentPaid`…).
- **التسوية كل 5 دقائق.** كثير من المتاجر الليبية خلف مضيفات لا يصل إليها DPay؛ `DPay::reconciler()` يعيد قراءة الجلسات المعلّقة ويشغّل نفس الانتقال.
- **`php artisan dpay:doctor`** يفحص الرموز والوضع والسرّ وعنوان الـ webhook ومخزن منع التكرار و`DPAY_STORE_UID` — بالعربية ثم الإنجليزية — و`--online` يستدعي الواجهة فعليًا.
- **`DPay::fake()`** يستبدل طبقة HTTP فقط: كل ما يرسله كودك يمرّ عبر SDK الحقيقي ويُسجَّل للتأكيد عليه.

## التثبيت

</div>

```bash
composer require dpay/laravel
php artisan vendor:publish --tag=dpay-config
php artisan dpay:doctor
```

<div dir="rtl">

ضع في `.env`:

</div>

```dotenv
DPAY_MODE=sandbox                 # أو live
DPAY_SANDBOX_TOKEN=sb_tk_...      # من لوحة التحكم ← بناء ← رموز الوصول (وضع الاختبار)
DPAY_API_TOKEN=...                # رمز تكامل role:api (الوضع الحي)
DPAY_WEBHOOK_SECRET=whsec_...     # يظهر مرة واحدة عند إنشاء نقطة الاستقبال
DPAY_STORE_UID=                   # dpay:doctor يولّد قيمة عشوائية — الصقها هنا ولا تنسخها إلى نسخة أخرى
```

<div dir="rtl">

سجّل عنوان الـ webhook في لوحة التحكم (بناء ← Webhooks): `https://متجرك/dpay/webhook`، واشترك في `checkout.*` و`payment.*`. يجب أن يكون العنوان https على مضيف عام (DPay لا يرسل إلى localhost أو عناوين خاصة، ولا يتبع إعادة التوجيه).

## البداية السريعة — الدفع المستضاف

### 1. أنشئ الجلسة وحوّل الزبون

</div>

```php
use DPay\Laravel\Facades\DPay;

public function pay(Order $order)
{
    $created = DPay::checkout()
        ->amount($order->total)                       // '125.50' — حتى منزلتين عشريتين، بالدينار
        ->forOrder($order->id, $order->pay_attempt)   // reference + مفتاح تكرار ثابت لكل (طلب، محاولة)
        ->description("طلب #{$order->id}")
        ->returnUrl(route('orders.dpay.return', $order))
        ->cancelUrl(route('cart'))
        ->metadata(['order_id' => $order->id])
        ->customer(name: $order->customer_name, phone: $order->phone)
        ->create();

    $order->update(['dpay_checkout_id' => $created->id, 'dpay_expires_at' => $created->expiresAt]);

    return $created;   // Responsable ← 303 إلى $created->url
}
```

<div dir="rtl">

النقر المزدوج يعيد نفس الجلسة (`$created->replayed`)؛ عند انتهاء الصلاحية زد `pay_attempt` لتحصل على جلسة جديدة.

### 2. عند العودة: اقرأ الحالة من الواجهة — لا تثق بالرابط

</div>

```php
use DPay\ReturnUrl\ReturnUrl;
use DPay\Models\CheckoutStatus;

public function returned(Request $request, Order $order)
{
    $hint = ReturnUrl::parse($request->query());          // checkoutSessionId, status — تلميح فقط
    abort_unless($hint->checkoutSessionId === $order->dpay_checkout_id, 404);

    $checkout = DPay::checkoutSessions()->get($order->dpay_checkout_id);   // الحقيقة
    app(OrderTransition::class)->apply($order, $checkout);

    return match ($checkout->status) {
        CheckoutStatus::Paid => view('orders.paid', compact('order')),
        CheckoutStatus::Open => view('orders.retry', ['url' => $checkout->url]),   // المحاولة فشلت، الجلسة ما زالت قابلة للدفع
        default => view('orders.expired', compact('order')),
    };
}
```

<div dir="rtl">

### 3. انتقال واحد آمن عند التكرار

نفس الدالة تُستدعى من صفحة العودة، ومن مستمع الـ webhook، ومن التسوية. تحرس المبلغ (بمنزلتين) والعملة والمرجع قبل أي إتمام:

</div>

```php
final class OrderTransition
{
    public function apply(Order $order, \DPay\Models\CheckoutSession $checkout): void
    {
        if (! $checkout->matchesOrder($order->total, 'LYD', (string) $order->id)) {
            $order->flagForReview('DPay amount/currency/reference mismatch');   // لا تُكمل الطلب أبدًا
            return;
        }
        if ($checkout->isPaid() && ! $order->isPaid()) {
            $order->markPaid(
                txId: $checkout->payment?->txId,
                amountCharged: $checkout->payment?->amountCharged->format(2),   // ما خُصم فعلًا (شامل الرسوم) — لا تُعد حساب الرسوم
                receiptUrl: $checkout->payment?->receiptUrl,
            );
        } elseif ($checkout->status === \DPay\Models\CheckoutStatus::Expired) {
            $order->freeForRetry();
        }
    }
}
```

<div dir="rtl">

### 4. الـ webhook

</div>

```php
// app/Providers/AppServiceProvider.php (Laravel 11+) أو EventServiceProvider
use DPay\Laravel\Events\CheckoutCompleted;
use DPay\Laravel\Events\CheckoutExpired;

Event::listen(CheckoutCompleted::class, function (CheckoutCompleted $event) {
    $order = Order::where('dpay_checkout_id', $event->checkoutSessionId())->firstOrFail();
    if (! $event->matchesOrder($order->total, 'LYD', (string) $order->id)) {
        return $order->flagForReview('webhook mismatch');
    }
    $order->markPaid($event->txId(), $event->amountCharged()?->format(2), $event->receiptUrl());
});
```

<div dir="rtl">

الحزمة تتولى التحقق والرفض ومنع التكرار والردّ بـ 200؛ عليك فقط أن يكون المستمع آمنًا عند التكرار (قد تصل التسوية أو صفحة العودة أولًا). إذا رمى المستمع استثناءً يُرفع 500 ويُلغى حجز منع التكرار فيعيد DPay المحاولة.

### 5. التسوية كل 5 دقائق

</div>

```php
// routes/console.php أو Kernel::schedule()
Schedule::call(function () {
    $ids = Order::pendingDPay()->where('created_at', '>', now()->subDay())->pluck('dpay_checkout_id');
    DPay::reconciler()->run($ids, fn ($checkout) => app(OrderTransition::class)->apply(
        Order::where('dpay_checkout_id', $checkout->id)->first(), $checkout,
    ));
})->everyFiveMinutes();
```

<div dir="rtl">

بدون دالة، يُطلق الحدث `CheckoutReconciled` لكل جلسة. الأمر `php artisan dpay:reconcile cs_… cs_…` يفعل الشيء نفسه من الطرفية. القراءات محدودة بـ 30 لكل تشغيل (حدّ القراءة 120 في الدقيقة).

## الأحداث

| الحدث | متى |
|---|---|
| `CheckoutCompleted` | دُفعت الجلسة — `payment()`, `txId()`, `amountCharged()`, `receiptUrl()` |
| `CheckoutExpired` / `CheckoutCancelled` | انتهت أو ألغاها التاجر |
| `PaymentPaid` / `PaymentFailed` / `PaymentExpired` / `PaymentRefunded` / `PaymentVoided` | لكل محاولة دفع (`payment.*`) — `checkoutSessionId()` يربطها بالجلسة |
| `WebhookReceived` | لكل تسليم موثّق غير مكرر، قبل الحدث المُنمّط |
| `WebhookTestReceived` | «إرسال اختبار» من لوحة التحكم |
| `CheckoutReconciled` | من التسوية بدون دالة |

## الإعدادات المهمة

| المفتاح | الافتراضي | |
|---|---|---|
| `DPAY_MODE` | `sandbox` | `live` يستخدم `DPAY_API_TOKEN` |
| `DPAY_BASE_URL` | `https://dpay.ly` | https فقط ومضيف DPay فقط؛ `DPAY_ALLOWED_HOSTS` للاستثناء الصريح |
| `DPAY_WEBHOOK_PATH` | `dpay/webhook` | |
| `DPAY_WEBHOOK_DEDUPE` | `cache` | `database` (انشر الهجرة) أو `none` |
| `DPAY_WEBHOOK_ENVIRONMENT_MISMATCH` | `ignore` | حدث اختباري على متجر حي: `ignore` = 200 وتجاهل، `reject` = 400 |
| `DPAY_WEBHOOK_DISPATCH` | `sync` | `queue` يردّ 200 فورًا ويعالج في عامل الطابور |
| `DPAY_WEBHOOK_PREVIOUS_SECRET` | — | يبقي السرّ القديم صالحًا أثناء التدوير |

## الاختبار

</div>

```php
$fake = DPay::fake();
$this->post(route('orders.pay', $order))->assertStatus(303);
$fake->assertCheckoutCreated(fn ($r) => $r->json['reference'] === (string) $order->id && $r->idempotencyKey() !== null);

$fake->markPaid($fake->lastCheckoutId(), ['tx_id' => 'txn_1']);
$this->get(route('orders.dpay.return', [$order, 'checkout_session_id' => $fake->lastCheckoutId(), 'status' => 'paid']));
$this->assertTrue($order->fresh()->isPaid());

[$raw, $server] = $fake->signedWebhook($fake->checkoutCompletedPayload($fake->lastCheckoutId()));
$this->call('POST', '/dpay/webhook', [], [], [], $server, $raw)->assertOk();
```

---

# English

The official DPay package for Laravel 10, 11 and 12: hosted checkout in one fluent call, a signed-webhook route with typed events, a bilingual `dpay:doctor`, and `DPay::fake()` for your tests. Built on [`dpay/dpay-php`](../php-sdk).

## What it does

- **Hosted checkout first.** Create a checkout session for a decimal LYD amount, redirect the customer to DPay's page, consume the result. No mobile numbers, card identifiers or OTPs ever transit your server, and you never hit the 5/min IP-keyed verify throttle.
- **Signed webhooks.** `POST /dpay/webhook` verifies the signature over the raw bytes (`hash_hmac('sha256', "{ts}.{raw}", $secret)` + `hash_equals`), refuses stale timestamps (300 s), checks the `live` flag against your mode, dedupes on `(live, id, event)`, then dispatches typed events.
- **Reconcile every 5 minutes.** Many Libyan stores sit behind hosts DPay cannot reach; `DPay::reconciler()` re-reads pending checkouts and runs the same transition.
- **`php artisan dpay:doctor`** validates tokens, mode, secret, webhook URL, dedupe store and `DPAY_STORE_UID` — Arabic first, English second — and `--online` calls the API.
- **`DPay::fake()`** swaps only the HTTP layer: everything your code sends goes through the real SDK and is recorded for assertions.

## Install

```bash
composer require dpay/laravel
php artisan vendor:publish --tag=dpay-config
php artisan dpay:doctor
```

```dotenv
DPAY_MODE=sandbox                 # or live
DPAY_SANDBOX_TOKEN=sb_tk_...      # dashboard → Build → API tokens (test mode)
DPAY_API_TOKEN=...                # a role:api integration token (live)
DPAY_WEBHOOK_SECRET=whsec_...     # shown once when you create the endpoint
DPAY_STORE_UID=                   # dpay:doctor prints a random one — keep it, never copy it to a clone
```

Register `https://your-store/dpay/webhook` in the dashboard (Build → Webhooks) and subscribe to `checkout.*` and `payment.*`. It must be an https URL on a public host — DPay never delivers to localhost/private IPs and never follows redirects.

## Hosted checkout in four steps

1. **Create + redirect** — `DPay::checkout()->amount('125.50')->forOrder($order->id, $attempt)->returnUrl(...)->create()` returns a `CreatedCheckout` (`id`, `url`, `expiresAt`, `replayed`) that is `Responsable` (303). `forOrder()` derives a deterministic `Idempotency-Key` from `platform:store_uid:order:attempt`, so a double-click replays the same checkout; bump `$attempt` after an expiry.
2. **Return** — parse the hint with `DPay\ReturnUrl\ReturnUrl::parse($request->query())`, then `DPay::checkoutSessions()->get($id)` is the truth. `status = open` means the attempt failed and the checkout is still payable: show "try again" and link `$checkout->url`.
3. **One idempotent transition** — guard with `$checkout->matchesOrder($total, 'LYD', $reference)` (amount at 2 dp, currency, reference); on mismatch never complete, flag for review. Record `payment->amountCharged` (2 dp, fee-inclusive) and `txId`; never recompute fees.
4. **Webhook + reconcile** — listen to `CheckoutCompleted` (same transition, idempotent by order state); schedule `DPay::reconciler()->run($pendingIds, $transition)` every five minutes (≤ 30 reads per run).

The Arabic section above carries the full code for each step.

## Events

`CheckoutCompleted`, `CheckoutExpired`, `CheckoutCancelled` (checkout-level, exactly once each), `PaymentPaid|Failed|Expired|Refunded|Voided` (per attempt; `checkoutSessionId()` links them), `WebhookReceived` (every verified non-duplicate delivery, before the typed one), `WebhookTestReceived` (dashboard "Send test"), `CheckoutReconciled` (from the reconciler without a callable). Every event exposes the verified `DPay\Webhooks\Event` as `$event->event`.

## The webhook route

| Situation | Answer |
|---|---|
| valid signature, fresh timestamp, first delivery | 200 `{ok, event, duplicate:false, reference}` and the events are dispatched |
| duplicate `(live, id, event)` | 200 `{duplicate:true}`, nothing dispatched |
| `webhook.test` | 200 `{test:true}`, `WebhookTestReceived` (never deduped) |
| bad signature / timestamp outside 300 s | **401** |
| missing headers / non-JSON body / no secret configured | **400** |
| `live` flag ≠ mode | 200 `{ignored:"environment"}` (default) or 400 with `DPAY_WEBHOOK_ENVIRONMENT_MISMATCH=reject` |
| a listener throws | **500**, the dedupe claim is released so DPay's retry is processed |

The route is registered outside the `web` group (no session, no CSRF). Extra middleware: `dpay.webhook.middleware`. Use `DPay\Laravel\Http\Middleware\VerifyDPaySignature` on your own route if you prefer — the verified event is the `dpay.event` request attribute.

Dedupe drivers: `cache` (default, `Cache::add`), `database` (`php artisan vendor:publish --tag=dpay-migrations && php artisan migrate` — a unique index makes the claim atomic across workers), `none`. `DPAY_WEBHOOK_DISPATCH=queue` answers 200 immediately and processes in a worker (`DPay\Laravel\Jobs\HandleWebhook`).

Secret rotation: put the new secret in `DPAY_WEBHOOK_SECRET` and the old one in `DPAY_WEBHOOK_PREVIOUS_SECRET` while queued retries drain, then remove the previous one.

## Testing with `DPay::fake()`

`DPay::fake([...config overrides])` returns a `DPayFake` whose in-memory API answers the real SDK with the platform's shapes: `markPaid($id, [...])`, `markExpired()`, `markCancelled()`, `seedCheckout($amount, $reference)`, `respondWith($method, $pathRegex, $status, $body, $headers)`, and assertions `assertCheckoutCreated(fn)`, `assertCheckoutCreatedCount()`, `assertNoCheckoutCreated()`, `assertNothingSent()`, `assertRequested()`, `assertCheckoutRead()`, `assertCheckoutCancelled()`, `assertIdempotencyKeysSent()`. `signedWebhook($payload)` returns `[rawBody, $server]` for `$this->call('POST', '/dpay/webhook', [], [], [], $server, $raw)`, and `checkoutCompletedPayload($id)` builds the platform's `checkout.completed` body for a paid fake checkout.

## Raw per-method API

`DPay::payMethods()`, `DPay::paymentSessions()` (open/verify/get), `DPay::payments()` and `DPay::client()` expose the SDK's raw surface for developers building their own UI. Read the [SDK README](../php-sdk/README.md) first: the verify route is throttled 5/min per IP, Mastercard charges USD on the raw path, and OTPs must never be logged.

## Requirements & support

PHP ≥ 8.1; Laravel `^10.0 || ^11.0 || ^12.0`; Guzzle 7 (the SDK builds it with TLS verification on, redirects off, 15 s / 5 s timeouts; `dpay:doctor` names the client actually in use and warns when it is one whose settings the SDK cannot verify). Secrets never leave the manager: `DPay::config()` / `webhookConfig()` return masked copies, `dd(app('dpay'))` shows no token, and the manager, the SDK client and the verifier refuse `serialize()` — a queued job resolves the `DPay` facade inside `handle()` instead of carrying them. Tested on the full matrix (PHP 8.1–8.5 × Laravel 10/11/12 where the framework supports the PHP version) with Orchestra Testbench — see `tools/matrix.sh`. Laravel 10 and 11 are past their security windows; the package supports them for merchants who still run them, but please upgrade.

## Docs

- API reference: https://dpay.ly/docs/api — Hosted Checkout, per-method sessions, webhooks.
- Help Center: https://dpay.ly/help — test mode, webhooks, tokens.
- SDK: [`dpay/dpay-php`](../php-sdk).

MIT — see [LICENSE](LICENSE). Changes in [CHANGELOG.md](CHANGELOG.md).
