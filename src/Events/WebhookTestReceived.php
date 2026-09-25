<?php

declare(strict_types=1);

namespace DPay\Laravel\Events;

/** `webhook.test` from the dashboard's "Send test" — verified, acknowledged, never a payment. */
final class WebhookTestReceived extends WebhookEvent {}
