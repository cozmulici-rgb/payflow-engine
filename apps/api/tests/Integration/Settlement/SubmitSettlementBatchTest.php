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
    'legal_name' => 'Settlement Failure Merchant Legal',
    'display_name' => 'Settlement Failure Merchant',
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

$create = $app->handle(new Request('POST', '/v1/transactions', $headers + [
    'Idempotency-Key' => 'settlement-failure-case-001',
], [
    'type' => 'authorization',
    'amount' => '75.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_settlement_fail'],
    'capture_mode' => 'manual',
    'metadata' => ['channel' => 'processor_b'],
]));

$app->processPendingTransactionCommands();
$app->handle(new Request('POST', '/v1/transactions/' . $create->body['data']['transaction_id'] . '/capture', $headers, ['amount' => '75.00']));

$result = $app->runSettlementWindow('2026-04-05', ['failure_processors' => ['processor_b']]);
$batches = $app->readSettlementBatches();
$artifacts = $app->readSettlementArtifacts();

TestCase::assertSame(1, $result['batch_count']);
TestCase::assertSame(0, $result['submitted_count']);
TestCase::assertSame(1, $result['exception_count']);
TestCase::assertSame(1, count($batches));
TestCase::assertSame('exception', $batches[0]['status']);
TestCase::assertSame('Settlement submission failed for processor [processor_b]', $batches[0]['exception_reason']);
TestCase::assertSame(1, count($artifacts));

$auditLog = $app->readAuditLog();
TestCase::assertSame('settlement.batch_exception', $auditLog[count($auditLog) - 1]['event_type']);
