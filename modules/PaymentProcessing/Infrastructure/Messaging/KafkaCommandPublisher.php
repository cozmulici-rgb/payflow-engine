<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Infrastructure\Messaging;

final class KafkaCommandPublisher
{
    public function __construct(
        private readonly string $path,
        private readonly string $topic
    ) {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function publish(array $payload): void
    {
        $items = [];
        if (is_file($this->path)) {
            $decoded = json_decode((string) file_get_contents($this->path), true);
            $items = is_array($decoded) ? $decoded : [];
        }

        $items[] = [
            'topic' => $this->topic,
            'published_at' => gmdate(DATE_ATOM),
            'payload' => $payload,
        ];

        file_put_contents($this->path, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
