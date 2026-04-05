<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Application\GetTransaction;

use Modules\PaymentProcessing\Domain\Transaction;

final class GetTransactionQuery
{
    /**
     * @return array<string,mixed>
     */
    public function toResponse(Transaction $transaction): array
    {
        return [
            'data' => [
                'transaction_id' => $transaction->id,
                'status' => $transaction->status->value,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'settlement_amount' => $transaction->settlementAmount,
                'settlement_currency' => $transaction->settlementCurrency,
                'processor_reference' => $transaction->processorReference,
                'error_code' => $transaction->errorCode,
                'updated_at' => $transaction->updatedAt,
            ],
        ];
    }
}
