<?php

declare(strict_types=1);

namespace Modules\Shared\Infrastructure\Workers;

use Modules\Audit\Application\WriteAuditRecord;
use Modules\MerchantManagement\Infrastructure\Persistence\WebhookEndpointRepository;
use Modules\Shared\Infrastructure\Http\WebhookSigner;
use Modules\Shared\Infrastructure\Persistence\WebhookDeliveryRepository;

final class WebhookDispatchWorker
{
    public function __construct(
        private readonly WebhookEndpointRepository $endpoints,
        private readonly WebhookDeliveryRepository $deliveries,
        private readonly WebhookSigner $signer,
        private readonly WriteAuditRecord $auditWriter,
        private readonly string $processedEventsPath
    ) {
    }

    /**
     * @param array<string,mixed> $event
     */
    public function handle(array $event): void
    {
        $eventId = (string) ($event['event_id'] ?? '');
        $merchantId = (string) ($event['merchant_id'] ?? '');
        $eventType = (string) ($event['event_type'] ?? '');
        if ($eventId === '' || $merchantId === '' || $eventType === '' || $this->alreadyProcessed($eventId)) {
            return;
        }

        $payload = json_encode($event, JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        foreach ($this->endpoints->findActiveByMerchantId($merchantId) as $endpoint) {
            if (!in_array($eventType, $endpoint->eventTypes, true)) {
                continue;
            }

            $deliveryId = $this->uuid();
            $this->deliveries->append([
                'id' => $deliveryId,
                'webhook_endpoint_id' => $endpoint->id,
                'event_id' => $eventId,
                'event_type' => $eventType,
                'merchant_id' => $merchantId,
                'url' => $endpoint->url,
                'attempt' => 1,
                'status' => 'delivered',
                'signature' => $this->signer->sign($payload, $endpoint->signingSecret, time()),
                'payload' => $event,
                'delivered_at' => gmdate(DATE_ATOM),
                'created_at' => gmdate(DATE_ATOM),
            ]);

            $this->auditWriter->handle([
                'event_type' => 'webhook.delivery_succeeded',
                'actor_id' => $merchantId,
                'action' => 'dispatch',
                'resource_type' => 'webhook_delivery',
                'resource_id' => $deliveryId,
                'correlation_id' => (string) ($event['correlation_id'] ?? ''),
                'context' => [
                    'webhook_endpoint_id' => $endpoint->id,
                    'event_id' => $eventId,
                    'attempt' => 1,
                ],
            ]);
        }

        $this->markProcessed($eventId);
    }

    private function alreadyProcessed(string $eventId): bool
    {
        foreach ($this->readProcessedEvents() as $event) {
            if (($event['consumer_group'] ?? null) === 'webhook-worker'
                && ($event['event_id'] ?? null) === $eventId) {
                return true;
            }
        }

        return false;
    }

    private function markProcessed(string $eventId): void
    {
        $events = $this->readProcessedEvents();
        $events[] = [
            'id' => $this->uuid(),
            'consumer_group' => 'webhook-worker',
            'event_id' => $eventId,
            'processed_at' => gmdate(DATE_ATOM),
        ];
        file_put_contents($this->processedEventsPath, json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function readProcessedEvents(): array
    {
        if (!is_file($this->processedEventsPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->processedEventsPath), true);
        return is_array($decoded) ? $decoded : [];
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
