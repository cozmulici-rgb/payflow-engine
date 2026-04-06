<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Infrastructure\Messaging;

/**
 * Publishes domain events to Kafka (or a local file in test/dev mode).
 *
 * Production: set $brokers and $topic; the class uses ext-rdkafka to produce
 * directly.  Test harness: $commandBusPath is set so events are appended to a
 * local JSON file instead, keeping the full test suite infrastructure-free.
 */
final class KafkaCommandPublisher
{
    private readonly bool $useFile;

    public function __construct(
        private readonly string $topic,
        private readonly string $brokers = '',
        private readonly string $commandBusPath = '',
    ) {
        $this->useFile = $commandBusPath !== '';
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function publish(array $payload): void
    {
        if ($this->useFile) {
            $this->appendToFile($payload);
            return;
        }

        $this->produceToKafka($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function produceToKafka(array $payload): void
    {
        $conf = new \RdKafka\Conf();
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('socket.keepalive.enable', 'true');

        $producer = new \RdKafka\Producer($conf);
        $kafkaTopic = $producer->newTopic($this->topic);

        $kafkaTopic->produce(
            RD_KAFKA_PARTITION_UA,
            0,
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
        );

        $producer->flush(10_000);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function appendToFile(array $payload): void
    {
        $items = [];
        if (is_file($this->commandBusPath)) {
            $decoded = json_decode((string) file_get_contents($this->commandBusPath), true);
            $items = is_array($decoded) ? $decoded : [];
        }

        $items[] = [
            'topic' => $this->topic,
            'published_at' => gmdate(DATE_ATOM),
            'payload' => $payload,
        ];

        file_put_contents(
            $this->commandBusPath,
            json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }
}
