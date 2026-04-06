<?php

declare(strict_types=1);

use App\Support\TestCase;
use Modules\PaymentProcessing\Infrastructure\Persistence\IdempotencyRepository;

$path = __DIR__ . '/../../../storage/test_idempotency.json';
@unlink($path);

$repository = new IdempotencyRepository($path);
$repository->storeAcceptedResponse('merchant-1:idem-1', [
    'status' => 202,
    'body' => [
        'data' => [
            'transaction_id' => 'trx-1',
            'status' => 'pending',
        ],
    ],
], new DateTimeImmutable('+1 day'));

$record = $repository->findResponseByKey('merchant-1:idem-1');

TestCase::assertSame(202, $record['status']);
TestCase::assertSame('trx-1', $record['body']['data']['transaction_id']);

@unlink($path);

// Test: missing key returns null
$path2 = __DIR__ . '/../../../storage/test_idempotency_missing.json';
@unlink($path2);
$repository2 = new IdempotencyRepository($path2);
TestCase::assertTrue($repository2->findResponseByKey('merchant-1:nonexistent') === null, 'Missing key should return null');
@unlink($path2);

// Test: expired key returns null
$path3 = __DIR__ . '/../../../storage/test_idempotency_expired.json';
@unlink($path3);
$repository3 = new IdempotencyRepository($path3);
$repository3->storeAcceptedResponse('merchant-1:expired', [
    'status' => 202,
    'body' => ['data' => ['transaction_id' => 'trx-expired']],
], new DateTimeImmutable('-1 second'));
TestCase::assertTrue($repository3->findResponseByKey('merchant-1:expired') === null, 'Expired key should return null');
@unlink($path3);
