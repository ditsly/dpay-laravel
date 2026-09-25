<?php

declare(strict_types=1);

namespace DPay\Laravel\Http\Controllers;

use DPay\Laravel\DPayManager;
use DPay\Laravel\Http\Middleware\VerifyDPaySignature;
use DPay\Laravel\Jobs\HandleWebhook;
use DPay\Laravel\Webhooks\WebhookProcessor;
use DPay\Webhooks\Event;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST {dpay.webhook.path}` — runs after {@see VerifyDPaySignature}.
 * Answers 200 for a processed, duplicate or test delivery; a listener
 * exception becomes a 500 (the claim is released, DPay retries). The short
 * JSON body is what the dashboard's delivery log shows to support.
 */
final class WebhookController
{
    public function __construct(
        private readonly DPayManager $dpay,
        private readonly WebhookProcessor $processor,
        private readonly BusDispatcher $bus,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $event = $request->attributes->get(VerifyDPaySignature::ATTRIBUTE);
        if (! $event instanceof Event) {
            return new JsonResponse(['ok' => false, 'error' => 'not_verified', 'message' => 'The route must run behind VerifyDPaySignature.'], 500);
        }

        $dispatch = $this->dpay->webhookConfig()['dispatch'] ?? 'sync';
        if ($dispatch === 'queue' && ! $event->isTest()) {
            $job = new HandleWebhook($event->rawBody, $event->timestamp);
            $queue = $this->dpay->webhookConfig()['queue'] ?? null;
            $connection = $this->dpay->webhookConfig()['connection'] ?? null;
            if (is_string($queue) && $queue !== '') {
                $job->onQueue($queue);
            }
            if (is_string($connection) && $connection !== '') {
                $job->onConnection($connection);
            }
            $this->bus->dispatch($job);

            return new JsonResponse(['ok' => true, 'event' => $event->name, 'queued' => true, 'reference' => $event->reference()], 200);
        }

        $outcome = $this->processor->process($event);

        return new JsonResponse([
            'ok' => true,
            'event' => $event->name,
            'duplicate' => $outcome === 'duplicate',
            'test' => $outcome === 'test',
            'reference' => $event->reference(),
        ], 200);
    }
}
