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
    'legal_name' => 'Settlement Merchant Legal',
    'display_name' => 'Settlement Merchant',
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

// Test: empty batch — no captured transactions → no batches created
$app->resetStorage();
$emptyResult = $app->runSettlementWindow('2026-04-06');
TestCase::assertSame(0, $emptyResult['batch_count'], 'No captured transactions should produce zero batches');
TestCase::assertSame(0, count($app->readSettlementBatches()), 'Settlement batch file should be empty');

// Test: multi-currency — CAD + USD captures → 2 separate batches
$app->resetStorage();
$merch2 = $app->handle(new Request('POST', '/internal/v1/merchants', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
    'X-Operator-Secret' => 'op-secret-change-me',
], [
    'legal_name' => 'Multi Currency Legal',
    'display_name' => 'Multi Currency',
    'country' => 'CA',
    'default_currency' => 'CAD',
]));
$merch2Id = $merch2->body['data']['merchant_id'];
$cred2 = $app->handle(new Request('POST', '/internal/v1/merchants/credentials', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
    'X-Operator-Secret' => 'op-secret-change-me',
], ['merchant_id' => $merch2Id]));
$headers2 = [
    'X-Merchant-Id' => $merch2Id,
    'X-Merchant-Key-Id' => $cred2->body['data']['key_id'],
    'X-Merchant-Secret' => $cred2->body['data']['secret'],
];

// CAD transaction (same currency, no FX)
$txnCad = $app->handle(new Request('POST', '/v1/transactions', $headers2 + ['Idempotency-Key' => 'idem-multi-cad'], [
    'type' => 'authorization',
    'amount' => '80.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_multi_cad'],
    'capture_mode' => 'manual',
]));
// CAD→USD cross-border transaction
$txnUsd = $app->handle(new Request('POST', '/v1/transactions', $headers2 + ['Idempotency-Key' => 'idem-multi-usd'], [
    'type' => 'authorization',
    'amount' => '100.00',
    'currency' => 'CAD',
    'settlement_currency' => 'USD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_multi_usd'],
    'capture_mode' => 'manual',
]));

$app->processPendingTransactionCommands();
$app->handle(new Request('POST', '/v1/transactions/' . $txnCad->body['data']['transaction_id'] . '/capture', $headers2, ['amount' => '80.00']));
$app->handle(new Request('POST', '/v1/transactions/' . $txnUsd->body['data']['transaction_id'] . '/capture', $headers2, ['amount' => '100.00']));

$multiResult = $app->runSettlementWindow('2026-04-07');
TestCase::assertSame(2, $multiResult['batch_count'], 'CAD and USD captures should produce 2 separate settlement batches');
$multiBatches = $app->readSettlementBatches();
TestCase::assertSame(2, count($multiBatches), 'Two settlement batches should be stored');
$currencies = array_map(static fn ($b) => $b['currency'], $multiBatches);
sort($currencies);
TestCase::assertSame(['CAD', 'USD'], $currencies, 'Batches should cover CAD and USD');
