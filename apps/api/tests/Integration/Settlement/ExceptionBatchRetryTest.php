<?php

declare(strict_types=1);

use App\Http\Request;
use App\Support\TestCase;

$app = bootstrap_app(__DIR__ . '/../../..');
$app->resetStorage();

$merchant = $app->handle(new Request('POST', '/internal/v1/merchants', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
            'X-Operator-Secret' => 'op-secret-change-me',
], [
    'legal_name' => 'Exception Retry Merchant Legal',
    'display_name' => 'Exception Retry Merchant',
    'country' => 'CA',
    'default_currency' => 'CAD',
]));

$merchantId = $merchant->body['data']['merchant_id'];
$credential = $app->handle(new Request('POST', '/internal/v1/merchants/credentials', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
            'X-Operator-Secret' => 'op-secret-change-me',
], ['merchant_id' => $merchantId]));

$headers = [
    'X-Merchant-Id' => $merchantId,
    'X-Merchant-Key-Id' => $credential->body['data']['key_id'],
    'X-Merchant-Secret' => $credential->body['data']['secret'],
];

// Create and capture a transaction on a processor that will fail settlement
$create = $app->handle(new Request('POST', '/v1/transactions', $headers + [
    'Idempotency-Key' => 'exception-retry-001',
], [
    'type' => 'authorization',
    'amount' => '100.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_retry'],
    'capture_mode' => 'manual',
    'metadata' => ['channel' => 'processor_b'],
]));

$app->processPendingTransactionCommands();
$app->handle(new Request('POST', '/v1/transactions/' . $create->body['data']['transaction_id'] . '/capture', $headers, ['amount' => '100.00']));

// First settlement run: processor_b fails, batch goes to exception
$result1 = $app->runSettlementWindow('2026-04-05', ['failure_processors' => ['processor_b']]);
TestCase::assertSame(1, $result1['batch_count']);
TestCase::assertSame(0, $result1['submitted_count']);
TestCase::assertSame(1, $result1['exception_count']);

// Second settlement run: no failures — the exception batch transaction should be re-eligible
$result2 = $app->runSettlementWindow('2026-04-06');
TestCase::assertSame(1, $result2['batch_count'], 'Transaction from exception batch should be re-batched');
TestCase::assertSame(1, $result2['submitted_count']);
TestCase::assertSame(0, $result2['exception_count']);
