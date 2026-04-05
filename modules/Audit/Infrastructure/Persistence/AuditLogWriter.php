<?php

declare(strict_types=1);

namespace Modules\Audit\Infrastructure\Persistence;

interface AuditLogWriter
{
    /**
     * @param array<string,mixed> $record
     */
    public function append(array $record): void;
}
