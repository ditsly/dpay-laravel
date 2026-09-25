<?php

declare(strict_types=1);

namespace DPay\Laravel\Jobs;

use DPay\Laravel\Webhooks\WebhookProcessor;
use DPay\Webhooks\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * `dpay.webhook.dispatch = queue`: the verified raw body is queued and the
 * events are dispatched by a worker, so the HTTP answer is immediate. The
 * body was already signature-checked; it is re-parsed, never re-verified
 * (the timestamp window would have closed by the time a delayed job runs).
 */
final class HandleWebhook implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $rawBody, public readonly ?string $timestamp) {}

    public function handle(WebhookProcessor $processor): void
    {
        $processor->process(Event::fromRawBody($this->rawBody, $this->timestamp));
    }
}
