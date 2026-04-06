<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Application\RegisterWebhook;

use Modules\Audit\Application\WriteAuditRecord;
use Modules\MerchantManagement\Domain\WebhookEndpoint;
use Modules\MerchantManagement\Infrastructure\Persistence\WebhookEndpointRepository;

final class RegisterWebhookHandler
{
    public function __construct(
        private readonly WebhookEndpointRepository $endpoints,
        private readonly WriteAuditRecord $auditWriter
    ) {
    }

    /**
     * @param list<string> $eventTypes
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(string $merchantId, string $url, array $eventTypes, string $correlationId): array
    {
        if (!str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return ['status' => 422, 'body' => ['message' => 'Webhook URL must use https']];
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $ip = gethostbyname($host);
        // Only block if DNS resolved to an IP (gethostbyname returns hostname unchanged on failure)
        if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['status' => 422, 'body' => ['message' => 'Webhook URL must not target private or reserved IP ranges']];
        }

        if ($eventTypes === []) {
            return ['status' => 422, 'body' => ['message' => 'At least one event type is required']];
        }

        $endpoint = new WebhookEndpoint(
            id: $this->uuid(),
            merchantId: $merchantId,
            url: $url,
            signingSecret: 'whsec_' . bin2hex(random_bytes(12)),
            eventTypes: array_values(array_unique($eventTypes)),
            status: 'active',
            createdAt: gmdate(DATE_ATOM)
        );

        $this->endpoints->save($endpoint);
        $this->auditWriter->handle([
            'event_type' => 'merchant.webhook_registered',
            'actor_id' => $merchantId,
            'action' => 'register',
            'resource_type' => 'webhook_endpoint',
            'resource_id' => $endpoint->id,
            'correlation_id' => $correlationId,
            'context' => [
                'url' => $endpoint->url,
                'event_types' => $endpoint->eventTypes,
            ],
        ]);

        return [
            'status' => 201,
            'body' => [
                'data' => [
                    'webhook_endpoint_id' => $endpoint->id,
                    'url' => $endpoint->url,
                    'event_types' => $endpoint->eventTypes,
                ],
            ],
        ];
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
