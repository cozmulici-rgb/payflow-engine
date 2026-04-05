<?php

declare(strict_types=1);

namespace Modules\Audit\Application;

use Modules\Audit\Infrastructure\Persistence\AuditLogWriter;

final class WriteAuditRecord
{
    public function __construct(private readonly AuditLogWriter $writer)
    {
    }

    /**
     * @param array<string,mixed> $record
     */
    public function handle(array $record): void
    {
        $record['created_at'] ??= gmdate(DATE_ATOM);
        $this->writer->append($record);
    }
}
