# Changelog

All notable changes to `dpay/laravel` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and the package uses
[Semantic Versioning](https://semver.org/). Versions track the SDK major
(`dpay/dpay-php`).

## [1.0.0] — 2026-09-25

First public release.

### Added
- `DPayServiceProvider` (auto-discovered): config merge + publish (`dpay-config`), the
  `DPayManager` singleton behind the `DPay` facade, `DPay\Client` and
  `DPay\Webhooks\Verifier` bindings, translations (`dpay-lang`), the migration stub
  (`dpay-migrations`), `php artisan about` section.
- `DPay::checkout()` fluent builder → `CreatedCheckout` (`Responsable`, 303 to the hosted
  page). `forOrder($id, $attempt)` derives a deterministic `Idempotency-Key` from
  `platform:store_uid:order:attempt` (DPAY_STORE_UID, DPAY_PLATFORM).
- Webhook route `POST {DPAY_WEBHOOK_PATH}` (default `dpay/webhook`, outside the `web` group)
  behind `VerifyDPaySignature`: raw-body HMAC with `hash_equals`, 300 s window, `live` flag
  vs `DPAY_MODE` (`ignore` → 200 / `reject` → 400), refusals 400 (missing headers, bad body,
  no secret) and 401 (signature, stale timestamp), rotation grace via
  `DPAY_WEBHOOK_PREVIOUS_SECRET`.
- Duplicate suppression on `(live, id, event)`: `cache` (default), `database`
  (`dpay_webhook_events` with a unique index) or `none`; a failing listener releases the
  claim and answers 500 so DPay's retry is processed.
- Typed events: `CheckoutCompleted`, `CheckoutExpired`, `CheckoutCancelled`, `PaymentPaid`,
  `PaymentFailed`, `PaymentExpired`, `PaymentRefunded`, `PaymentVoided`, `WebhookReceived`,
  `WebhookTestReceived`, `CheckoutReconciled`; `CheckoutEvent::matchesOrder()` guard.
- `DPAY_WEBHOOK_DISPATCH=queue`: immediate 200, processing in `Jobs\HandleWebhook`.
- `Reconciler` (`DPay::reconciler()->run($ids, $transition)`) capped at
  `dpay.reconcile.limit` reads per run, and `php artisan dpay:reconcile`.
- `php artisan dpay:doctor [--online] [--lang=both|ar|en] [--json]` — Arabic first, then
  English: mode, token kind, base URL policy, webhook secret format, public https webhook
  URL, dedupe store, store uid, HTTP client; `--online` calls `/api/health` and lists usable
  pay methods.
- `DPay::fake()` → `DPayFake`: an in-memory DPay API behind the real SDK (recording
  transport), `markPaid/markExpired/markCancelled/seedCheckout/respondWith`, request
  assertions, `signedWebhook()` and `checkoutCompletedPayload()` helpers.
- Orchestra Testbench suite (64 tests) and `tools/matrix.sh` for PHP 8.1–8.5 × Laravel
  10/11/12 in Docker; Larastan level max; Pint (laravel preset + strict types).

### Security
- `DPayManager::config()` and `webhookConfig()` return copies with `api_token`, `sandbox_token`,
  `webhook.secret` and `webhook.previous_secret` masked; `dd(app('dpay'))` shows no secret and the
  manager refuses `serialize()` (a queued job must resolve the facade, not carry the manager).
  `rawToken()` serves `dpay:doctor`'s whitespace check only.

[1.0.0]: https://github.com/ditsly/dpay-laravel/releases/tag/v1.0.0
