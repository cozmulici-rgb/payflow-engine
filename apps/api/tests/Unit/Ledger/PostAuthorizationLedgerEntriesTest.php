<?php

declare(strict_types=1);

use App\Support\TestCase;
use Database\Seeders\ChartOfAccountsSeeder;
use Modules\Ledger\Application\PostAuthorizationLedgerEntries;
use Modules\Ledger\Infrastructure\Persistence\LedgerRepository;
use Modules\PaymentProcessing\Domain\Transaction;
use Modules\PaymentProcessing\Domain\TransactionStatus;

$storage = __DIR__ . '/../../../storage';
$accountsPath = $storage . '/test_accounts.json';
$journalPath = $storage . '/test_journal_entries.json';
$ledgerPath = $storage . '/test_ledger_entries.json';

foreach ([$accountsPath, $journalPath, $ledgerPath] as $path) {
    @unlink($path);
}

$repository = new LedgerRepository($accountsPath, $journalPath, $ledgerPath);
(new ChartOfAccountsSeeder($repository))->seed();
$service = new PostAuthorizationLedgerEntries($repository);

$transaction = new Transaction(
    id: 'trx-ledger-1',
    merchantId: 'merchant-1',
    idempotencyKey: 'idem-ledger-1',
    type: 'authorization',
    amount: '100.00',
    currency: 'CAD',
    settlementCurrency: 'USD',
    paymentMethodType: 'card_token',
    paymentMethodToken: 'tok_1',
    captureMode: 'manual',
    reference: 'order-1',
    status: TransactionStatus::Authorized,
    processorId: 'processor_a',
    processorReference: 'proc-auth-1',
    settlementAmount: '74.0000',
    fxRateLockId: 'fx-lock-1',
    errorCode: null,
    errorMessage: null,
    metadata: ['channel' => 'web'],
    createdAt: '2026-04-05T00:00:00+00:00',
    updatedAt: '2026-04-05T00:00:02+00:00'
);

$journalEntryId = $service->postAuthorization($transaction);
$accounts = json_decode((string) file_get_contents($accountsPath), true);
$journals = json_decode((string) file_get_contents($journalPath), true);
$entries = json_decode((string) file_get_contents($ledgerPath), true);

$accountCodes = [];
foreach ($accounts as $account) {
    $accountCodes[$account['id']] = $account['code'];
}

TestCase::assertSame(1, count($journals));
TestCase::assertSame($journalEntryId, $journals[0]['id']);
TestCase::assertSame('transaction.authorization', $journals[0]['reference_type']);
TestCase::assertSame(2, count($entries));
TestCase::assertSame($journalEntryId, $entries[0]['journal_entry_id']);
TestCase::assertSame($journalEntryId, $entries[1]['journal_entry_id']);

$entryTypes = [$entries[0]['entry_type'], $entries[1]['entry_type']];
sort($entryTypes);
TestCase::assertSame(['credit', 'debit'], $entryTypes);
TestCase::assertSame('74.0000', $entries[0]['amount']);
TestCase::assertSame('74.0000', $entries[1]['amount']);
$codes = array_values(array_unique([
    $accountCodes[$entries[0]['account_id']],
    $accountCodes[$entries[1]['account_id']],
]));
sort($codes);
TestCase::assertSame(['merchant_payable', 'processor_receivable'], $codes);

$refundTransaction = new Transaction(
    id: 'trx-ledger-2',
    merchantId: 'merchant-1',
    idempotencyKey: 'idem-ledger-2',
    type: 'authorization',
    amount: '100.00',
    currency: 'CAD',
    settlementCurrency: 'USD',
    paymentMethodType: 'card_token',
    paymentMethodToken: 'tok_1',
    captureMode: 'manual',
    reference: 'order-2',
    status: TransactionStatus::Refunded,
    processorId: 'processor_a',
    processorReference: 'proc-refund-1',
    settlementAmount: '74.0000',
    fxRateLockId: 'fx-lock-2',
    errorCode: null,
    errorMessage: null,
    metadata: ['refunded_amount' => '20.00'],
    createdAt: '2026-04-05T00:00:00+00:00',
    updatedAt: '2026-04-05T00:01:00+00:00'
);

$refundJournalEntryId = $service->postRefund($refundTransaction);
$refundJournals = json_decode((string) file_get_contents($journalPath), true);
$refundEntries = json_decode((string) file_get_contents($ledgerPath), true);

TestCase::assertSame(2, count($refundJournals));
TestCase::assertSame($refundJournalEntryId, $refundJournals[1]['id']);
TestCase::assertSame('transaction.refund', $refundJournals[1]['reference_type']);
TestCase::assertSame(4, count($refundEntries));
TestCase::assertSame('14.8000', $refundEntries[2]['amount']);
TestCase::assertSame('14.8000', $refundEntries[3]['amount']);
TestCase::assertSame('USD', $refundEntries[2]['currency']);
TestCase::assertSame('USD', $refundEntries[3]['currency']);

foreach ([$accountsPath, $journalPath, $ledgerPath] as $path) {
    @unlink($path);
}
