<?php

declare(strict_types=1);

use App\Support\TestCase;
use Database\Seeders\ChartOfAccountsSeeder;
use Modules\Ledger\Infrastructure\Persistence\LedgerRepository;

$storage = __DIR__ . '/../../../storage';
$accountsPath = $storage . '/test_seed_accounts.json';
$journalPath = $storage . '/test_seed_journal_entries.json';
$ledgerPath = $storage . '/test_seed_ledger_entries.json';

foreach ([$accountsPath, $journalPath, $ledgerPath] as $path) {
    @unlink($path);
}

$existingCreatedAt = '2026-04-01T10:00:00+00:00';
file_put_contents($accountsPath, json_encode([
    [
        'id' => 'acct-existing-processor',
        'code' => 'processor_receivable',
        'name' => 'Processor Receivable',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'currency' => null,
        'created_at' => $existingCreatedAt,
        'updated_at' => $existingCreatedAt,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$repository = new LedgerRepository($accountsPath, $journalPath, $ledgerPath);
(new ChartOfAccountsSeeder($repository))->seed();

$accounts = json_decode((string) file_get_contents($accountsPath), true);
TestCase::assertSame(9, count($accounts));

$processorReceivable = null;
$merchantPayable = null;
foreach ($accounts as $account) {
    if (($account['code'] ?? null) === 'processor_receivable') {
        $processorReceivable = $account;
    }

    if (($account['code'] ?? null) === 'merchant_payable') {
        $merchantPayable = $account;
    }
}

TestCase::assertSame('acct-existing-processor', $processorReceivable['id'] ?? null);
TestCase::assertSame($existingCreatedAt, $processorReceivable['created_at'] ?? null);
TestCase::assertTrue(($merchantPayable['id'] ?? '') !== '', 'Expected missing account to be created');

foreach ([$accountsPath, $journalPath, $ledgerPath] as $path) {
    @unlink($path);
}
