<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Infrastructure\Persistence;

use Modules\PaymentProcessing\Domain\Transaction;

final class EloquentTransaction
{
    /**
     * @param array<string,mixed> $attributes
     */
    public function __construct(public readonly array $attributes)
    {
    }

    public static function fromDomain(Transaction $transaction): self
    {
        return new self($transaction->toArray());
    }

    public function toDomain(): Transaction
    {
        return Transaction::fromArray($this->attributes);
    }
}
