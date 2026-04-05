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
    'legal_name' => 'Refund Merchant Legal',
    'display_name' => 'Refund Merchant',
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
    'Idempotency-Key' => 'idem-refund-001',
    'X-Correlation-Id' => 'corr-refund-create',
], [
    'type' => 'authorization',
    'amount' => '125.50',
    'currency' => 'CAD',
    'settlement_currency' => 'USD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_refund'],
    'capture_mode' => 'manual',
]));

$transactionId = $create->body['data']['transaction_id'];
$app->processPendingTransactionCommands();
$app->handle(new Request('POST', '/v1/transactions/' . $transactionId . '/capture', $headers, ['amount' => '100.00']));

$refund = $app->handle(new Request('POST', '/v1/transactions/' . $transactionId . '/refund', $headers + [
    'X-Correlation-Id' => 'corr-refund-run',
], [
    'amount' => '20.00',
]));

TestCase::assertSame(202, $refund->status);
TestCase::assertSame('refunded', $refund->body['data']['status']);
TestCase::assertSame('20.00', $refund->body['data']['refund_amount']);

$transaction = $app->transactionRepository()->findById($transactionId);
TestCase::assertSame('refunded', $transaction?->status->value);
TestCase::assertSame('20.00', $transaction?->metadata['refunded_amount']);
TestCase::assertSame(2, count($app->readJournalEntries()));

$ledgerEntries = $app->readLedgerEntries();
TestCase::assertSame(4, count($ledgerEntries));
TestCase::assertSame('14.8000', $ledgerEntries[2]['amount']);
TestCase::assertSame('14.8000', $ledgerEntries[3]['amount']);
TestCase::assertSame('USD', $ledgerEntries[2]['currency']);
TestCase::assertSame('USD', $ledgerEntries[3]['currency']);

$invalid = $app->handle(new Request('POST', '/v1/transactions/' . $transactionId . '/refund', $headers, [
    'amount' => '200.00',
]));
TestCase::assertSame(422, $invalid->status);
