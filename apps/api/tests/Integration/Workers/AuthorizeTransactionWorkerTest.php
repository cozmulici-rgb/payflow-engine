<?php

declare(strict_types=1);

use App\Http\Request;
use App\Support\TestCase;

$app = bootstrap_app(__DIR__ . '/../../..');

$createMerchant = static function (string $displayName) use ($app): array {
    $merchant = $app->handle(new Request(
        'POST',
        '/internal/v1/merchants',
        [
            'X-Operator-Id' => 'op-123',
            'X-Operator-Role' => 'merchant.write',
            'X-Operator-Secret' => 'op-secret-change-me',
        ],
        [
            'legal_name' => $displayName . ' Legal',
            'display_name' => $displayName,
            'country' => 'CA',
            'default_currency' => 'CAD',
        ]
    ));

    $merchantId = $merchant->body['data']['merchant_id'];
    $credential = $app->handle(new Request(
        'POST',
        '/internal/v1/merchants/credentials',
        [
            'X-Operator-Id' => 'op-123',
            'X-Operator-Role' => 'merchant.write',
            'X-Operator-Secret' => 'op-secret-change-me',
        ],
        ['merchant_id' => $merchantId]
    ));

    return [$merchantId, $credential->body['data']['key_id'], $credential->body['data']['secret']];
};

$createTransaction = static function (array $authHeaders, array $body) use ($app): string {
    $response = $app->handle(new Request(
        'POST',
        '/v1/transactions',
        $authHeaders,
        $body
    ));

    return $response->body['data']['transaction_id'];
};

$app->resetStorage();
[$merchantId, $keyId, $secret] = $createMerchant('Approved Merchant');
$approvedTransactionId = $createTransaction([
    'X-Merchant-Id' => $merchantId,
    'X-Merchant-Key-Id' => $keyId,
    'X-Merchant-Secret' => $secret,
    'Idempotency-Key' => 'idem-worker-approved',
    'X-Correlation-Id' => 'corr-worker-approved',
], [
    'type' => 'authorization',
    'amount' => '100.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok-approved'],
    'capture_mode' => 'manual',
    'metadata' => ['channel' => 'web'],
]);

$processedCount = $app->processPendingTransactionCommands();
TestCase::assertSame(1, $processedCount);
$approvedTransaction = $app->transactionRepository()->findById($approvedTransactionId);
TestCase::assertSame('authorized', $approvedTransaction?->status->value);
TestCase::assertSame('processor_a', $approvedTransaction?->processorId);
TestCase::assertSame(1, count($app->readProcessedEvents()));
$bus = $app->readCommandBus();
TestCase::assertSame('transaction.authorized', $bus[1]['payload']['event_type']);

$app->resetStorage();
[$merchantId, $keyId, $secret] = $createMerchant('Fraud Merchant');
$fraudTransactionId = $createTransaction([
    'X-Merchant-Id' => $merchantId,
    'X-Merchant-Key-Id' => $keyId,
    'X-Merchant-Secret' => $secret,
    'Idempotency-Key' => 'idem-worker-fraud',
    'X-Correlation-Id' => 'corr-worker-fraud',
], [
    'type' => 'authorization',
    'amount' => '99.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok-fraud'],
    'metadata' => ['channel' => 'fraud'],
]);

$app->processPendingTransactionCommands();
$fraudTransaction = $app->transactionRepository()->findById($fraudTransactionId);
TestCase::assertSame('failed', $fraudTransaction?->status->value);
TestCase::assertSame('fraud_rejected', $fraudTransaction?->errorCode);
TestCase::assertSame('transaction.failed', $app->readCommandBus()[1]['payload']['event_type']);

$app->resetStorage();
[$merchantId, $keyId, $secret] = $createMerchant('FX Merchant');
$timeoutTransactionId = $createTransaction([
    'X-Merchant-Id' => $merchantId,
    'X-Merchant-Key-Id' => $keyId,
    'X-Merchant-Secret' => $secret,
    'Idempotency-Key' => 'idem-worker-timeout',
    'X-Correlation-Id' => 'corr-worker-timeout',
], [
    'type' => 'authorization',
    'amount' => '50.00',
    'currency' => 'CAD',
    'settlement_currency' => 'USD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok-timeout'],
    'metadata' => ['channel' => 'timeout-confirm'],
]);

$app->processPendingTransactionCommands();
$timeoutTransaction = $app->transactionRepository()->findById($timeoutTransactionId);
TestCase::assertSame('authorized', $timeoutTransaction?->status->value);
TestCase::assertSame('processor_a', $timeoutTransaction?->processorId);
TestCase::assertTrue($timeoutTransaction?->settlementAmount !== null, 'Expected FX settlement amount');
TestCase::assertSame(1, count($app->readRateLocks()));
TestCase::assertTrue($app->readRateLocks()[0]['used_at'] !== null, 'Expected used FX rate lock');

$app->processPendingTransactionCommands();
TestCase::assertSame(1, count($app->readProcessedEvents()));

// Test: timeout-fail channel — processor timeout + inquiry fails → transaction marked Failed
$app->resetStorage();
[$merchantId, $keyId, $secret] = $createMerchant('Timeout-Fail Merchant');
$timeoutFailId = $createTransaction([
    'X-Merchant-Id' => $merchantId,
    'X-Merchant-Key-Id' => $keyId,
    'X-Merchant-Secret' => $secret,
    'Idempotency-Key' => 'idem-worker-timeout-fail',
    'X-Correlation-Id' => 'corr-worker-timeout-fail',
], [
    'type' => 'authorization',
    'amount' => '75.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok-timeout-fail'],
    'metadata' => ['channel' => 'timeout-fail'],
]);

$app->processPendingTransactionCommands();
$timeoutFailTxn = $app->transactionRepository()->findById($timeoutFailId);
TestCase::assertSame('failed', $timeoutFailTxn?->status->value, 'Transaction should be failed after timeout+inquiry failure');
TestCase::assertSame('processor_timeout', $timeoutFailTxn?->errorCode, 'Error code should be processor_timeout');

$events = array_filter($app->readCommandBus(), static fn ($e) => ($e['payload']['event_type'] ?? '') === 'transaction.failed');
TestCase::assertSame(1, count(array_values($events)), 'One transaction.failed event should be published');
