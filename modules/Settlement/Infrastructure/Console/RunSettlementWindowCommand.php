<?php

declare(strict_types=1);

namespace Modules\Settlement\Infrastructure\Console;

use Modules\Settlement\Application\CreateSettlementBatch\CreateSettlementBatchHandler;
use Modules\Settlement\Application\SubmitSettlementBatch\SubmitSettlementBatchHandler;

final class RunSettlementWindowCommand
{
    public function __construct(
        private readonly CreateSettlementBatchHandler $createBatches,
        private readonly SubmitSettlementBatchHandler $submitBatches
    ) {
    }

    /**
     * @return array{batch_count:int,submitted_count:int,exception_count:int}
     */
    public function handle(string $batchDate): array
    {
        $created = $this->createBatches->handle($batchDate);
        $submitted = 0;
        $exceptions = 0;

        foreach ($created as $batch) {
            $result = $this->submitBatches->handle($batch);
            if ($result->status === 'submitted') {
                $submitted++;
                continue;
            }

            if ($result->status === 'exception') {
                $exceptions++;
            }
        }

        return [
            'batch_count' => count($created),
            'submitted_count' => $submitted,
            'exception_count' => $exceptions,
        ];
    }
}
