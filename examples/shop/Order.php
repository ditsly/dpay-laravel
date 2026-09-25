<?php

declare(strict_types=1);

namespace DPay\Laravel\Examples\Shop;

/**
 * A stand-in for your Eloquent order. The four fields DPay needs are the
 * checkout id, the attempt counter, the total as a 2 dp decimal string,
 * and a paid flag; everything else is your own.
 */
final class Order
{
    public function __construct(
        public int $id,
        public string $total,
        public string $customerName,
        public string $phone,
        public ?string $dpayCheckoutId = null,
        public int $payAttempt = 1,
        public ?\DateTimeImmutable $dpayExpiresAt = null,
        public bool $paid = false,
        public ?string $txId = null,
        public ?string $note = null,
    ) {}

    public function markPaid(?string $txId, ?string $amountCharged, ?string $receiptUrl): void
    {
        $this->paid = true;
        $this->txId = $txId;
        $this->note = sprintf('charged %s, receipt %s', $amountCharged ?? '?', $receiptUrl ?? '—');
    }

    public function flagForReview(string $reason): void
    {
        $this->note = 'REVIEW: '.$reason;
    }

    public function freeForRetry(): void
    {
        $this->dpayCheckoutId = null;
        $this->payAttempt++;
    }
}
