<?php

declare(strict_types=1);

namespace DPay\Laravel\Events;

/** `checkout.cancelled` — merchant-initiated only (the cancel route). */
final class CheckoutCancelled extends CheckoutEvent {}
