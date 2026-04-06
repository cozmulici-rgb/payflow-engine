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
    'legal_name' => 'Refund Merchant Legal',
    'display_name' => 'Refund Merchant',
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

// Test: full refund — captured amount equals authorized amount, fully refunded
$app->resetStorage();
$merch2 = $app->handle(new Request('POST', '/internal/v1/merchants', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
    'X-Operator-Secret' => 'op-secret-change-me',
], [
    'legal_name' => 'Full Refund Merchant Legal',
    'display_name' => 'Full Refund Merchant',
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
$txn2 = $app->handle(new Request('POST', '/v1/transactions', $headers2 + ['Idempotency-Key' => 'idem-full-refund'], [
    'type' => 'authorization',
    'amount' => '50.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_full_refund'],
    'capture_mode' => 'manual',
]));
$txnId2 = $txn2->body['data']['transaction_id'];
$app->processPendingTransactionCommands();
$app->handle(new Request('POST', '/v1/transactions/' . $txnId2 . '/capture', $headers2, ['amount' => '50.00']));
$fullRefund = $app->handle(new Request('POST', '/v1/transactions/' . $txnId2 . '/refund', $headers2, ['amount' => '50.00']));
TestCase::assertSame(202, $fullRefund->status, 'Full refund of captured amount should succeed');
TestCase::assertSame('refunded', $fullRefund->body['data']['status']);
TestCase::assertSame('50.00', $fullRefund->body['data']['refund_amount']);

// Test: refund on settled transaction — Settled status allows refund
$app->resetStorage();
$merch3 = $app->handle(new Request('POST', '/internal/v1/merchants', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
    'X-Operator-Secret' => 'op-secret-change-me',
], [
    'legal_name' => 'Settled Refund Merchant Legal',
    'display_name' => 'Settled Refund Merchant',
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
$txn3 = $app->handle(new Request('POST', '/v1/transactions', $headers3 + ['Idempotency-Key' => 'idem-settled-refund'], [
    'type' => 'authorization',
    'amount' => '30.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_settled_refund'],
    'capture_mode' => 'manual',
]));
$txnId3 = $txn3->body['data']['transaction_id'];
$app->processPendingTransactionCommands();
$app->handle(new Request('POST', '/v1/transactions/' . $txnId3 . '/capture', $headers3, ['amount' => '30.00']));
// Manually advance to Settled to simulate post-settlement refund
$app->transactionRepository()->updateStatus($txnId3, \Modules\PaymentProcessing\Domain\TransactionStatus::Captured, \Modules\PaymentProcessing\Domain\TransactionStatus::Settled);
$settledRefund = $app->handle(new Request('POST', '/v1/transactions/' . $txnId3 . '/refund', $headers3, ['amount' => '30.00']));
TestCase::assertSame(202, $settledRefund->status, 'Refund on settled transaction should succeed');
TestCase::assertSame('refunded', $settledRefund->body['data']['status']);

// Test: processor-rejected refund → 422 with error_code
$app->resetStorage();
$merch4 = $app->handle(new Request('POST', '/internal/v1/merchants', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
    'X-Operator-Secret' => 'op-secret-change-me',
], [
    'legal_name' => 'Ref Fail Merchant Legal',
    'display_name' => 'Ref Fail Merchant',
    'country' => 'CA',
    'default_currency' => 'CAD',
]));
$merch4Id = $merch4->body['data']['merchant_id'];
$cred4 = $app->handle(new Request('POST', '/internal/v1/merchants/credentials', [
    'X-Operator-Id' => 'op-123',
    'X-Operator-Role' => 'merchant.write',
    'X-Operator-Secret' => 'op-secret-change-me',
], ['merchant_id' => $merch4Id]));
$headers4 = [
    'X-Merchant-Id' => $merch4Id,
    'X-Merchant-Key-Id' => $cred4->body['data']['key_id'],
    'X-Merchant-Secret' => $cred4->body['data']['secret'],
];
$txn4 = $app->handle(new Request('POST', '/v1/transactions', $headers4 + ['Idempotency-Key' => 'idem-ref-fail'], [
    'type' => 'authorization',
    'amount' => '40.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok_ref_fail'],
    'capture_mode' => 'manual',
    'metadata' => ['channel' => 'ref-fail'],
]));
$txnId4 = $txn4->body['data']['transaction_id'];
$app->processPendingTransactionCommands();
$app->handle(new Request('POST', '/v1/transactions/' . $txnId4 . '/capture', $headers4, ['amount' => '40.00']));
$rejectedRefund = $app->handle(new Request('POST', '/v1/transactions/' . $txnId4 . '/refund', $headers4, ['amount' => '40.00']));
TestCase::assertSame(422, $rejectedRefund->status, 'Processor-rejected refund should return 422');
TestCase::assertSame('processor_refund_failed', $rejectedRefund->body['error_code'] ?? '', 'Expected processor_refund_failed error code');
