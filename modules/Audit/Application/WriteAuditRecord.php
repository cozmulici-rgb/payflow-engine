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
        $record['event_id'] ??= $this->uuid();
        $record['created_at'] ??= gmdate(DATE_ATOM);
        $this->writer->append($record);
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
