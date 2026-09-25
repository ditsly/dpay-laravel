<?php

declare(strict_types=1);

use DPay\Laravel\Http\Controllers\WebhookController;
use DPay\Laravel\Http\Middleware\VerifyDPaySignature;
use Illuminate\Support\Facades\Route;

/*
 * POST {dpay.webhook.path} — registered by the service provider when
 * `dpay.webhook.enabled` is true. No `web` group: no session, no CSRF (DPay
 * posts JSON with its own signature). Add throttling or IP rules through
 * `dpay.webhook.middleware` if your edge needs them.
 */
$path = config('dpay.webhook.path', 'dpay/webhook');
$extra = config('dpay.webhook.middleware', []);
/** @var list<string> $middleware */
$middleware = [];
foreach (is_array($extra) ? $extra : [] as $name) {
    if (is_string($name) && $name !== '') {
        $middleware[] = $name;
    }
}
$middleware[] = VerifyDPaySignature::class;

Route::post(is_string($path) ? trim($path, '/') : 'dpay/webhook', WebhookController::class)
    ->middleware($middleware)
    ->name('dpay.webhook');
