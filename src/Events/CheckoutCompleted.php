<?php

declare(strict_types=1);

namespace DPay\Laravel\Events;

/** `checkout.completed` — exactly once per checkout; `payment()` names the winning attempt. */
final class CheckoutCompleted extends CheckoutEvent {}
