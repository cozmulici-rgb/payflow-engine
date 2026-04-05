<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Infrastructure\Workers;

use Modules\PaymentProcessing\Application\AuthorizeTransaction\AuthorizeTransactionHandler;

final class ProcessTransactionWorker
{
    public function __construct(private readonly AuthorizeTransactionHandler $handler)
    {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function handle(array $payload): void
    {
        $this->handler->handle($payload);
    }
}
