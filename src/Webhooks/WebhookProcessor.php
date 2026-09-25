<?php

declare(strict_types=1);

namespace DPay\Laravel\Webhooks;

use DPay\Laravel\Events\WebhookReceived;
use DPay\Laravel\Events\WebhookTestReceived;
use DPay\Webhooks\Event;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * After verification: claim the dedupe key, dispatch {@see WebhookReceived}
 * then the typed event, release the claim when a listener throws (so the
 * retry is processed). `webhook.test` is dispatched without dedupe — every
 * dashboard test must reach the app.
 */
final class WebhookProcessor
{
    public function __construct(private readonly Dispatcher $events, private readonly Deduper $deduper) {}

    /** @return 'processed'|'duplicate'|'test' */
    public function process(Event $event): string
    {
        if ($event->isTest()) {
            $this->events->dispatch(new WebhookTestReceived($event));

            return 'test';
        }
        $key = $event->dedupeKey();
        if (! $this->deduper->claim($key, $event->name)) {
            return 'duplicate';
        }
        try {
            $this->events->dispatch(new WebhookReceived($event));
            $typed = EventMap::typed($event);
            if ($typed !== null) {
                $this->events->dispatch($typed);
            }
        } catch (\Throwable $e) {
            $this->deduper->release($key);
            throw $e;
        }

        return 'processed';
    }
}
