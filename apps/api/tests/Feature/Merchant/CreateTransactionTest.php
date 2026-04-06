<?php

declare(strict_types=1);

use App\Http\Request;
use App\Support\TestCase;

$app = bootstrap_app(__DIR__ . '/../../..');
$app->resetStorage();

$merchantResponse = $app->handle(new Request(
    'POST',
    '/internal/v1/merchants',
    [
        'X-Operator-Id' => 'op-123',
        'X-Operator-Role' => 'merchant.write',
            'X-Operator-Secret' => 'op-secret-change-me',
        'X-Correlation-Id' => 'corr-merchant',
    ],
    [
        'legal_name' => 'Acme Payments Canada Inc.',
        'display_name' => 'Acme Payments',
        'country' => 'CA',
        'default_currency' => 'CAD',
    ]
));

$merchantId = $merchantResponse->body['data']['merchant_id'];
$credentialResponse = $app->handle(new Request(
    'POST',
    '/internal/v1/merchants/credentials',
    [
        'X-Operator-Id' => 'op-123',
        'X-Operator-Role' => 'merchant.write',
            'X-Operator-Secret' => 'op-secret-change-me',
        'X-Correlation-Id' => 'corr-credential',
    ],
    [
        'merchant_id' => $merchantId,
    ]
));

$headers = [
    'X-Merchant-Id' => $merchantId,
    'X-Merchant-Key-Id' => $credentialResponse->body['data']['key_id'],
    'X-Merchant-Secret' => $credentialResponse->body['data']['secret'],
    'Idempotency-Key' => 'idem-transaction-001',
    'X-Correlation-Id' => 'corr-transaction',
];

$body = [
    'type' => 'authorization',
    'amount' => '125.50',
    'currency' => 'CAD',
    'payment_method' => [
        'type' => 'card_token',
        'token' => 'tok_123',
    ],
    'capture_mode' => 'manual',
    'reference' => 'order-10001',
    'metadata' => [
        'channel' => 'web',
    ],
];

$response = $app->handle(new Request('POST', '/v1/transactions', $headers, $body));

TestCase::assertSame(202, $response->status);
TestCase::assertSame('pending', $response->body['data']['status']);
TestCase::assertSame('idem-transaction-001', $response->body['data']['idempotency_key']);

$transactions = $app->readTransactions();
TestCase::assertSame(1, count($transactions));
TestCase::assertSame($merchantId, $transactions[0]['merchant_id']);
TestCase::assertSame('pending', $transactions[0]['status']);

$history = $app->readTransactionStatusHistory();
TestCase::assertSame(1, count($history));
TestCase::assertSame('pending', $history[0]['status']);

$commands = $app->readCommandBus();
TestCase::assertSame(1, count($commands));
TestCase::assertSame('transaction.processing', $commands[0]['topic']);
TestCase::assertSame('transaction.process', $commands[0]['payload']['command']);

$audit = $app->readAuditLog();
TestCase::assertSame('transaction.created', $audit[2]['event_type']);
TestCase::assertSame('corr-transaction', $audit[2]['correlation_id']);

$replay = $app->handle(new Request('POST', '/v1/transactions', $headers, $body));

TestCase::assertSame(202, $replay->status);
TestCase::assertSame($response->body['data']['transaction_id'], $replay->body['data']['transaction_id']);
TestCase::assertSame(1, count($app->readTransactions()));
TestCase::assertSame(1, count($app->readCommandBus()));
TestCase::assertSame(1, count($app->readIdempotencyRecords()));

$invalidCurrency = $app->handle(new Request(
    'POST',
    '/v1/transactions',
    [
        'X-Merchant-Id' => $merchantId,
        'X-Merchant-Key-Id' => $credentialResponse->body['data']['key_id'],
        'X-Merchant-Secret' => $credentialResponse->body['data']['secret'],
        'Idempotency-Key' => 'idem-transaction-002',
    ],
    [
        'type' => 'authorization',
        'amount' => '10.00',
        'currency' => 'USD',
        'payment_method' => [
            'type' => 'card_token',
            'token' => 'tok_456',
        ],
    ]
));

TestCase::assertSame(422, $invalidCurrency->status);
TestCase::assertSame('Validation failed', $invalidCurrency->body['message']);
TestCase::assertArrayHasKey('currency', $invalidCurrency->body['errors']);
TestCase::assertSame(1, count($app->readTransactions()));
