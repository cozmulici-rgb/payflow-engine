<?php

declare(strict_types=1);

namespace Modules\Settlement\Application\CreateSettlementBatch;

use Modules\PaymentProcessing\Domain\TransactionStatus;
use Modules\PaymentProcessing\Infrastructure\Persistence\TransactionRepository;
use Modules\Settlement\Domain\SettlementBatch;
use Modules\Settlement\Infrastructure\Persistence\SettlementBatchRepository;

final class CreateSettlementBatchHandler
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly SettlementBatchRepository $batches
    ) {
    }

    /**
     * @return list<SettlementBatch>
     */
    public function handle(string $batchDate): array
    {
        $batchedIds = array_fill_keys($this->batches->batchedTransactionIds(), true);
        $groups = [];

        foreach ($this->transactions->all() as $transaction) {
            if ($transaction->status !== TransactionStatus::Captured) {
                continue;
            }

            if (isset($batchedIds[$transaction->id])) {
                continue;
            }

            $processorId = (string) ($transaction->processorId ?? 'unknown_processor');
            $currency = strtoupper($transaction->settlementCurrency !== '' ? $transaction->settlementCurrency : $transaction->currency);
            $key = $processorId . ':' . $currency;
            $groups[$key] ??= [
                'processor_id' => $processorId,
                'currency' => $currency,
                'transactions' => [],
            ];
            $groups[$key]['transactions'][] = $transaction;
        }

        $created = [];
        foreach ($groups as $group) {
            $created[] = $this->batches->createOpen(
                $group['processor_id'],
                $group['currency'],
                $batchDate,
                $group['transactions']
            );
        }

        return $created;
    }
}
