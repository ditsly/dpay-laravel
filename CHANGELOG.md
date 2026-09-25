# Changelog

All notable changes to `dpay/laravel` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and the package uses
[Semantic Versioning](https://semver.org/). Versions track the SDK major
(`dpay/dpay-php`).

## [1.1.0] — 2026-09-25

### Added
- **Laravel 13** support: `illuminate/*` `^10.0 || ^11.0 || ^12.0 || ^13.0` (Laravel 13 needs
  PHP ≥ 8.3). `composer require dpay/laravel` on a fresh Laravel 13 application failed to
  resolve with 1.0.0.
- **Guzzle 8** support: `guzzlehttp/guzzle` `^7.5 || ^8.0`. Laravel 13 installs Guzzle 8;
  Laravel 11 and 12 require Guzzle 7. Laravel 10 does not constrain Guzzle, so Composer also
  admits Guzzle 8 there and the package works on it — but Laravel 10's own HTTP client calls
  `RequestException` methods Guzzle 8 removed, so a Laravel 10 application must keep
  `"guzzlehttp/guzzle": "^7"` in its own `composer.json` (the Laravel 10 skeleton ships `^7.2`;
  restore it if it was removed, or `composer update` can lift Guzzle to 8). The SDK-built client
  is hardened the same way on both majors, and `dpay:doctor` names the major actually installed
  (`Guzzle 8 (GuzzleHttp\Client)`).
- Test matrix: PHP 8.3, 8.4 and 8.5 × Laravel 13 on Guzzle 8, Laravel 13 on Guzzle 7, and
  Laravel 10 on Guzzle 8 (PHP 8.2) (Orchestra Testbench 11, PHPUnit 13 allowed); every
  Laravel 10–12 cell is kept.

### Fixed
- `LICENSE` names the copyright holder correctly: **Dimensions IT Solutions (DITS)**, Libya —
  https://dits.ly. 1.0.0 carried a wrong expansion of the acronym.

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

[1.1.0]: https://github.com/ditsly/dpay-laravel/releases/tag/v1.1.0
[1.0.0]: https://github.com/ditsly/dpay-laravel/releases/tag/v1.0.0
