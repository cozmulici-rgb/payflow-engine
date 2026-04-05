<?php

declare(strict_types=1);

use App\Http\Request;
use App\Support\TestCase;

$app = bootstrap_app(__DIR__ . '/../../..');
$app->resetStorage();

$merchant = $app->handle(new Request('POST', '/internal/v1/merchants', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
], [
    'legal_name' => 'Settlement Merchant Legal',
    'display_name' => 'Settlement Merchant',
    'country' => 'CA',
    'default_currency' => 'CAD',
]));

$merchantId = $merchant->body['data']['merchant_id'];
$credential = $app->handle(new Request('POST', '/internal/v1/merchants/credentials', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
], ['merchant_id' => $merchantId]));

$headers = [
    'X-Merchant-Id' => $merchantId,
    'X-Merchant-Key-Id' => $credential->body['data']['key_id'],
    'X-Merchant-Secret' => $credential->body['data']['secret'],
];

$createA = $app->handle(new Request('POST', '/v1/transactions', $headers + [
    'Idempotency-Key' => 'idem-settlement-001',
], [
    'type' => 'authorization',
    'amount' => '80.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_settlement_1'],
    'capture_mode' => 'manual',
]));

$createB = $app->handle(new Request('POST', '/v1/transactions', $headers + [
    'Idempotency-Key' => 'idem-settlement-002',
], [
    'type' => 'authorization',
    'amount' => '50.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_settlement_2'],
    'capture_mode' => 'manual',
]));

$app->processPendingTransactionCommands();
$app->handle(new Request('POST', '/v1/transactions/' . $createA->body['data']['transaction_id'] . '/capture', $headers, ['amount' => '80.00']));
$app->handle(new Request('POST', '/v1/transactions/' . $createB->body['data']['transaction_id'] . '/capture', $headers, ['amount' => '50.00']));

$result = $app->runSettlementWindow('2026-04-05');
$batches = $app->readSettlementBatches();
$items = $app->readSettlementItems();
$artifacts = $app->readSettlementArtifacts();

TestCase::assertSame(1, $result['batch_count']);
TestCase::assertSame(1, $result['submitted_count']);
TestCase::assertSame(0, $result['exception_count']);
TestCase::assertSame(1, count($batches));
TestCase::assertSame('submitted', $batches[0]['status']);
TestCase::assertSame(2, $batches[0]['item_count']);
TestCase::assertSame('130.0000', $batches[0]['total_amount']);
TestCase::assertSame(2, count($items));
TestCase::assertTrue(($batches[0]['artifact_key'] ?? '') !== '', 'Expected stored artifact key');
TestCase::assertSame(1, count($artifacts));

$submittedEventFound = false;
foreach ($app->readCommandBus() as $message) {
    if (($message['payload']['event_type'] ?? null) === 'settlement.batch.submitted') {
        $submittedEventFound = true;
        break;
    }
}
TestCase::assertTrue($submittedEventFound, 'Expected settlement.batch.submitted event');

$submittedAuditFound = false;
foreach ($app->readAuditLog() as $record) {
    if (($record['event_type'] ?? null) === 'settlement.batch_submitted') {
        $submittedAuditFound = true;
        break;
    }
}
TestCase::assertTrue($submittedAuditFound, 'Expected settlement.batch_submitted audit record');
