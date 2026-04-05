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
    'legal_name' => 'Capture Merchant Legal',
    'display_name' => 'Capture Merchant',
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
