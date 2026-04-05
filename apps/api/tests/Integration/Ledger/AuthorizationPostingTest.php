<?php

declare(strict_types=1);

use App\Http\Request;
use App\Support\TestCase;
use Modules\Audit\Application\WriteAuditRecord;
use Modules\Audit\Infrastructure\Persistence\FileAuditLogWriter;
use Modules\FXCrossBorder\Application\LockRate\FxRateLockService;
use Modules\FXCrossBorder\Infrastructure\Persistence\RateLockRepository;
use Modules\Ledger\Application\PostAuthorizationLedgerEntries;
use Modules\Ledger\Infrastructure\Persistence\LedgerRepository;
use Modules\PaymentProcessing\Application\AuthorizeTransaction\AuthorizeTransactionHandler;
use Modules\PaymentProcessing\Infrastructure\Messaging\KafkaCommandPublisher;
use Modules\PaymentProcessing\Infrastructure\Providers\Fraud\FraudScreeningService;
use Modules\PaymentProcessing\Infrastructure\Providers\Processor\ProcessorRouter;

$basePath = __DIR__ . '/../../..';
$storage = $basePath . '/storage';
$app = bootstrap_app($basePath);

$createMerchant = static function (string $displayName) use ($app): array {
    $merchant = $app->handle(new Request(
        'POST',
        '/internal/v1/merchants',
        [
            'X-Operator-Id' => 'op-123',
            'X-Operator-Role' => 'merchant.write',
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
[$merchantId, $keyId, $secret] = $createMerchant('Ledger Merchant');
$transactionId = $createTransaction([
    'X-Merchant-Id' => $merchantId,
    'X-Merchant-Key-Id' => $keyId,
    'X-Merchant-Secret' => $secret,
    'Idempotency-Key' => 'idem-ledger-approved',
    'X-Correlation-Id' => 'corr-ledger-approved',
], [
    'type' => 'authorization',
    'amount' => '100.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok-approved'],
    'capture_mode' => 'manual',
    'metadata' => ['channel' => 'web'],
]);

$processedCount = $app->processPendingTransactionCommands();
$transaction = $app->transactionRepository()->findById($transactionId);
$journals = $app->readJournalEntries();
$ledgerEntries = $app->readLedgerEntries();
$accounts = $app->readAccounts();
$auditLog = $app->readAuditLog();

TestCase::assertSame(1, $processedCount);
TestCase::assertSame('authorized', $transaction?->status->value);
TestCase::assertSame(1, count($journals));
TestCase::assertSame(2, count($ledgerEntries));

$accountCodes = [];
foreach ($accounts as $account) {
    $accountCodes[$account['id']] = $account['code'];
}
$codes = [
    $accountCodes[$ledgerEntries[0]['account_id']],
    $accountCodes[$ledgerEntries[1]['account_id']],
];
sort($codes);
TestCase::assertSame(['merchant_payable', 'processor_receivable'], $codes);
TestCase::assertSame($journals[0]['id'], $ledgerEntries[0]['journal_entry_id']);

$ledgerAuditFound = false;
foreach ($auditLog as $item) {
    if (($item['event_type'] ?? null) === 'ledger.authorization_posted') {
        $ledgerAuditFound = true;
        break;
    }
}
TestCase::assertTrue($ledgerAuditFound, 'Expected ledger authorization audit entry');

$app->resetStorage();
[$merchantId, $keyId, $secret] = $createMerchant('Rollback Merchant');
$rollbackTransactionId = $createTransaction([
    'X-Merchant-Id' => $merchantId,
    'X-Merchant-Key-Id' => $keyId,
    'X-Merchant-Secret' => $secret,
    'Idempotency-Key' => 'idem-ledger-failed',
    'X-Correlation-Id' => 'corr-ledger-failed',
], [
    'type' => 'authorization',
    'amount' => '40.00',
    'currency' => 'CAD',
    'payment_method' => ['type' => 'card_token', 'token' => 'tok-approved'],
    'capture_mode' => 'manual',
    'metadata' => ['channel' => 'web'],
]);

$handler = new AuthorizeTransactionHandler(
    $app->transactionRepository(),
    new ProcessorRouter(),
    new FraudScreeningService(),
    new FxRateLockService(new RateLockRepository($storage . '/rate_locks.json'), $basePath . '/config/payflow.php'),
    new KafkaCommandPublisher($storage . '/command_bus.json', 'transaction.events'),
    new WriteAuditRecord(new FileAuditLogWriter($storage . '/audit_log.json')),
    new PostAuthorizationLedgerEntries(new LedgerRepository(
        $storage . '/missing_accounts.json',
        $storage . '/journal_entries.json',
        $storage . '/ledger_entries.json'
    )),
    $storage . '/processed_events.json',
    $basePath . '/config/payflow.php'
);

$failed = false;
try {
    $handler->handle($app->readCommandBus()[0]['payload']);
} catch (RuntimeException) {
    $failed = true;
}

$rolledBackTransaction = $app->transactionRepository()->findById($rollbackTransactionId);

TestCase::assertTrue($failed, 'Expected authorization ledger posting to fail');
TestCase::assertSame('pending', $rolledBackTransaction?->status->value);
TestCase::assertSame([], $app->readJournalEntries());
TestCase::assertSame([], $app->readLedgerEntries());
TestCase::assertSame([], $app->readProcessedEvents());
TestCase::assertSame([], $app->readRateLocks());
