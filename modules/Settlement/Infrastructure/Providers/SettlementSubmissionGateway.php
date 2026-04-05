<?php

declare(strict_types=1);

namespace Modules\Settlement\Infrastructure\Providers;

use Modules\Settlement\Domain\SettlementBatch;

final class SettlementSubmissionGateway
{
    public function __construct(private readonly array $failureProcessors = [])
    {
    }

    public function submit(SettlementBatch $batch, string $artifactKey): void
    {
        if (in_array($batch->processorId, $this->failureProcessors, true)) {
            throw new \RuntimeException(sprintf('Settlement submission failed for processor [%s]', $batch->processorId));
        }

        if ($artifactKey === '') {
            throw new \RuntimeException('Settlement artifact key is required');
        }
    }
}
