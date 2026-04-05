<?php

declare(strict_types=1);

use App\Support\TestCase;
use Modules\Audit\Application\WriteAuditRecord;
use Modules\Audit\Infrastructure\Persistence\FileAuditLogWriter;

$path = __DIR__ . '/../../../storage/test_audit.json';
@unlink($path);

$writer = new WriteAuditRecord(new FileAuditLogWriter($path));
$writer->handle([
    'event_type' => 'merchant.created',
    'actor_id' => 'op-1',
    'action' => 'create',
    'resource_type' => 'merchant',
    'resource_id' => 'm-1',
    'correlation_id' => 'corr-1',
    'context' => ['country' => 'CA'],
]);

$items = json_decode((string) file_get_contents($path), true);
TestCase::assertSame(1, count($items));
TestCase::assertSame('merchant.created', $items[0]['event_type']);
TestCase::assertArrayHasKey('created_at', $items[0]);

@unlink($path);
