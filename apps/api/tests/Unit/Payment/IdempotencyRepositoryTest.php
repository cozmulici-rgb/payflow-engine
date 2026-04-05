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
