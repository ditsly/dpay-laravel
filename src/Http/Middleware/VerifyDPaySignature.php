<?php

declare(strict_types=1);

namespace DPay\Laravel\Http\Middleware;

use DPay\Exceptions\InvalidSignatureException;
use DPay\Exceptions\StaleTimestampException;
use DPay\Exceptions\UnexpectedResponseException;
use DPay\Exceptions\WebhookEnvironmentMismatchException;
use DPay\Laravel\DPayManager;
use DPay\Laravel\Exceptions\NotConfiguredException;
use DPay\Webhooks\Event;
use DPay\Webhooks\Signature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies an inbound DPay webhook over the RAW body (`$request->getContent()`
 * — never the parsed array: re-encoding changes `\/` and `\uXXXX` escapes and
 * breaks the HMAC), with hash_equals, the 300 s timestamp window and the
 * `live` flag against `dpay.mode`. On success the verified
 * {@see Event} is attached as the `dpay.event` request attribute.
 *
 * Refusals (never 2xx, so DPay retries and the dashboard shows the failure):
 *   400 — missing X-DPAY-Timestamp / X-DPAY-Signature, non-JSON body, no secret configured
 *   401 — signature does not match, timestamp outside the window
 * Environment mismatch (`live:false` on a live store or vice versa):
 *   200 `ignored` by default (`dpay.webhook.environment_mismatch = ignore`), or 400 with `reject`.
 *
 * Use it on your own route too: `Route::post('hooks/dpay', …)->middleware(VerifyDPaySignature::class)`.
 */
final class VerifyDPaySignature
{
    public const ATTRIBUTE = 'dpay.event';

    public function __construct(private readonly DPayManager $dpay) {}

    /** @param \Closure(Request): Response $next */
    public function handle(Request $request, \Closure $next): Response
    {
        $timestamp = $request->headers->get(Signature::TIMESTAMP_HEADER);
        $signature = $request->headers->get(Signature::HEADER);
        if ($timestamp === null || trim($timestamp) === '') {
            return self::refuse(400, 'missing_timestamp', 'Missing X-DPAY-Timestamp.');
        }
        if ($signature === null || trim($signature) === '') {
            return self::refuse(400, 'missing_signature', 'Missing X-DPAY-Signature.');
        }

        try {
            $verifier = $this->dpay->verifier();
        } catch (NotConfiguredException $e) {
            return self::refuse(400, 'not_configured', $e->getMessage());
        }

        $raw = (string) $request->getContent();
        try {
            $event = $verifier->verifyParts($raw, $timestamp, $signature);
        } catch (StaleTimestampException $e) {
            return self::refuse(preg_match('/^\d{1,12}$/', trim($timestamp)) === 1 ? 401 : 400, 'stale_timestamp', $e->getMessage());
        } catch (InvalidSignatureException $e) {
            return self::refuse(401, 'invalid_signature', $e->getMessage());
        } catch (WebhookEnvironmentMismatchException $e) {
            if (($this->dpay->webhookConfig()['environment_mismatch'] ?? 'ignore') === 'reject') {
                return self::refuse(400, 'environment_mismatch', $e->getMessage());
            }

            return new JsonResponse(['ok' => true, 'ignored' => 'environment', 'mode' => $this->dpay->mode()], 200);
        } catch (UnexpectedResponseException $e) {
            return self::refuse(400, 'invalid_body', $e->getMessage());
        }

        $request->attributes->set(self::ATTRIBUTE, $event);

        return $next($request);
    }

    private static function refuse(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}
