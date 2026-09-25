<?php

declare(strict_types=1);

namespace DPay\Laravel\Events;

/** `checkout.expired` — free the order for another attempt; your pending-order policy decides. */
final class CheckoutExpired extends CheckoutEvent {}
