<?php

declare(strict_types=1);

namespace Modules\Settlement\Application\SubmitSettlementBatch;

use Modules\Audit\Application\WriteAuditRecord;
use Modules\PaymentProcessing\Infrastructure\Messaging\KafkaCommandPublisher;
use Modules\Settlement\Domain\SettlementBatch;
use Modules\Settlement\Infrastructure\Files\SettlementFileGenerator;
use Modules\Settlement\Infrastructure\Persistence\SettlementBatchRepository;
use Modules\Settlement\Infrastructure\Providers\SettlementSubmissionGateway;
use Modules\Settlement\Infrastructure\Storage\SettlementArtifactStore;

final class SubmitSettlementBatchHandler
{
    public function __construct(
        private readonly SettlementBatchRepository $batches,
        private readonly SettlementFileGenerator $files,
        private readonly SettlementArtifactStore $artifacts,
        private readonly SettlementSubmissionGateway $gateway,
        private readonly WriteAuditRecord $audit,
        private readonly KafkaCommandPublisher $events
    ) {
    }

    public function handle(SettlementBatch $batch): SettlementBatch
    {
        try {
            $items = $this->batches->itemsForBatch($batch->id);
            $artifactKey = $this->artifacts->store($batch->id, $this->files->generate($batch, $items));
            $this->gateway->submit($batch, $artifactKey);
            $submitted = $this->batches->markSubmitted($batch->id, $artifactKey, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

            $this->audit->handle([
                'event_type' => 'settlement.batch_submitted',
                'actor_id' => 'scheduler',
                'action' => 'submit',
                'resource_type' => 'settlement_batch',
                'resource_id' => $submitted->id,
                'correlation_id' => 'settlement-window',
                'context' => [
                    'artifact_key' => $artifactKey,
                    'item_count' => $submitted->itemCount,
                    'total_amount' => $submitted->totalAmount,
                ],
            ]);

            $this->events->publish([
                'event_id' => $this->uuid(),
                'event_type' => 'settlement.batch.submitted',
                'batch_id' => $submitted->id,
                'processor_id' => $submitted->processorId,
                'currency' => $submitted->currency,
                'item_count' => $submitted->itemCount,
                'total_amount' => $submitted->totalAmount,
                'submitted_at' => $submitted->submittedAt,
            ]);

            return $submitted;
        } catch (\Throwable $exception) {
            $failed = $this->batches->markException($batch->id, $exception->getMessage());
            $this->audit->handle([
                'event_type' => 'settlement.batch_exception',
                'actor_id' => 'scheduler',
                'action' => 'submit',
                'resource_type' => 'settlement_batch',
                'resource_id' => $failed->id,
                'correlation_id' => 'settlement-window',
                'context' => [
                    'reason' => $failed->exceptionReason,
                ],
            ]);

            return $failed;
        }
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
