<?php

declare(strict_types=1);

namespace Modules\Settlement\Infrastructure\Files;

use Modules\Settlement\Domain\SettlementBatch;

final class SettlementFileGenerator
{
    /**
     * @param list<array<string,mixed>> $items
     */
    public function generate(SettlementBatch $batch, array $items): string
    {
        $lines = [
            'batch_id,processor_id,currency,transaction_id,processor_reference,amount',
        ];

        foreach ($items as $item) {
            $lines[] = implode(',', [
                $batch->id,
                $batch->processorId,
                $batch->currency,
                (string) ($item['transaction_id'] ?? ''),
                (string) ($item['processor_reference'] ?? ''),
                (string) ($item['amount'] ?? ''),
            ]);
        }

        return implode("\n", $lines) . "\n";
    }
}
