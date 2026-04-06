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
    'legal_name' => 'Capture Merchant Legal',
    'display_name' => 'Capture Merchant',
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
    'Idempotency-Key' => 'idem-capture-001',
    'X-Correlation-Id' => 'corr-capture-create',
], [
    'type' => 'authorization',
    'amount' => '125.50',
    'currency' => 'CAD',
    'settlement_currency' => 'USD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_capture'],
    'capture_mode' => 'manual',
]));

$transactionId = $create->body['data']['transaction_id'];
$app->processPendingTransactionCommands();

$capture = $app->handle(new Request('POST', '/v1/transactions/' . $transactionId . '/capture', $headers + [
    'X-Correlation-Id' => 'corr-capture-run',
], [
    'amount' => '100.00',
]));

TestCase::assertSame(202, $capture->status);
TestCase::assertSame('captured', $capture->body['data']['status']);
TestCase::assertSame('100.00', $capture->body['data']['captured_amount']);

$transaction = $app->transactionRepository()->findById($transactionId);
TestCase::assertSame('captured', $transaction?->status->value);
TestCase::assertSame('100.00', $transaction?->metadata['captured_amount']);
TestCase::assertSame('92.8700', $transaction?->settlementAmount);
TestCase::assertSame('transaction.captured', $app->readCommandBus()[2]['payload']['event_type']);

$invalid = $app->handle(new Request('POST', '/v1/transactions/' . $transactionId . '/capture', $headers, [
    'amount' => '200.00',
]));
TestCase::assertSame(422, $invalid->status);

// Test: full capture — exact authorized amount succeeds
$app->resetStorage();
$merch2 = $app->handle(new Request('POST', '/internal/v1/merchants', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
    'X-Operator-Secret' => 'op-secret-change-me',
], [
    'legal_name' => 'Full Capture Merchant Legal',
    'display_name' => 'Full Capture Merchant',
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
$txn2 = $app->handle(new Request('POST', '/v1/transactions', $headers2 + ['Idempotency-Key' => 'idem-full-cap'], [
    'type' => 'authorization',
    'amount' => '125.50',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_full_cap'],
    'capture_mode' => 'manual',
]));
$txnId2 = $txn2->body['data']['transaction_id'];
$app->processPendingTransactionCommands();
$fullCapture = $app->handle(new Request('POST', '/v1/transactions/' . $txnId2 . '/capture', $headers2, ['amount' => '125.50']));
TestCase::assertSame(202, $fullCapture->status, 'Full capture of exact authorized amount should succeed');
TestCase::assertSame('captured', $fullCapture->body['data']['status']);
TestCase::assertSame('125.50', $fullCapture->body['data']['captured_amount']);

// Test: processor-rejected capture → 422 with error_code
$app->resetStorage();
$merch3 = $app->handle(new Request('POST', '/internal/v1/merchants', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
    'X-Operator-Secret' => 'op-secret-change-me',
], [
    'legal_name' => 'Cap Fail Merchant Legal',
    'display_name' => 'Cap Fail Merchant',
    'country' => 'CA',
    'default_currency' => 'CAD',
]));
$merch3Id = $merch3->body['data']['merchant_id'];
$cred3 = $app->handle(new Request('POST', '/internal/v1/merchants/credentials', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
    'X-Operator-Secret' => 'op-secret-change-me',
], ['merchant_id' => $merch3Id]));
$headers3 = [
    'X-Merchant-Id' => $merch3Id,
    'X-Merchant-Key-Id' => $cred3->body['data']['key_id'],
    'X-Merchant-Secret' => $cred3->body['data']['secret'],
];
$txn3 = $app->handle(new Request('POST', '/v1/transactions', $headers3 + ['Idempotency-Key' => 'idem-cap-fail'], [
    'type' => 'authorization',
    'amount' => '50.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_cap_fail'],
    'capture_mode' => 'manual',
    'metadata' => ['channel' => 'cap-fail'],
]));
$txnId3 = $txn3->body['data']['transaction_id'];
$app->processPendingTransactionCommands();
$rejectedCapture = $app->handle(new Request('POST', '/v1/transactions/' . $txnId3 . '/capture', $headers3, ['amount' => '50.00']));
TestCase::assertSame(422, $rejectedCapture->status, 'Processor-rejected capture should return 422');
TestCase::assertSame('processor_capture_failed', $rejectedCapture->body['error_code'] ?? '', 'Expected processor_capture_failed error code');
