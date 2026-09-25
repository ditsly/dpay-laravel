<?php

declare(strict_types=1);

namespace DPay\Laravel\Events;

/**
 * Dispatched for EVERY verified, non-duplicate delivery (including event
 * names this package version does not know), before the typed event.
 */
final class WebhookReceived extends WebhookEvent {}
