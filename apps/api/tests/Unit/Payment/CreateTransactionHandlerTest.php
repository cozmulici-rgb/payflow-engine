<?php

declare(strict_types=1);

use App\Support\TestCase;
use Modules\Audit\Application\WriteAuditRecord;
use Modules\Audit\Infrastructure\Persistence\FileAuditLogWriter;
use Modules\PaymentProcessing\Application\CreateTransaction\CreateTransactionCommand;
use Modules\PaymentProcessing\Application\CreateTransaction\CreateTransactionHandler;
use Modules\PaymentProcessing\Infrastructure\Messaging\KafkaCommandPublisher;
use Modules\PaymentProcessing\Infrastructure\Persistence\IdempotencyRepository;
use Modules\PaymentProcessing\Infrastructure\Persistence\TransactionRepository;

$storage = __DIR__ . '/../../../storage';
$transactionsPath = $storage . '/test_transactions.json';
$historyPath = $storage . '/test_transaction_history.json';
$idempotencyPath = $storage . '/test_idempotency_handler.json';
$commandsPath = $storage . '/test_command_bus.json';
$auditPath = $storage . '/test_transaction_audit.json';

foreach ([$transactionsPath, $historyPath, $idempotencyPath, $commandsPath, $auditPath] as $path) {
    @unlink($path);
}

$handler = new CreateTransactionHandler(
    new TransactionRepository($transactionsPath, $historyPath),
    new IdempotencyRepository($idempotencyPath),
    new KafkaCommandPublisher(topic: 'transaction.processing', commandBusPath: $commandsPath),
    new WriteAuditRecord(new FileAuditLogWriter($auditPath))
);

$command = new CreateTransactionCommand(
    merchantId: 'merchant-1',
    idempotencyKey: 'idem-1',
    type: 'authorization',
    amount: '12.34',
    currency: 'CAD',
    settlementCurrency: 'CAD',
    paymentMethodType: 'card_token',
    paymentMethodToken: 'tok_1',
    captureMode: 'manual',
    reference: 'order-1',
    metadata: ['channel' => 'web'],
    correlationId: 'corr-1'
);

$first = $handler->handle($command);
$second = $handler->handle($command);

TestCase::assertSame(202, $first['status']);
TestCase::assertSame($first['body']['data']['transaction_id'], $second['body']['data']['transaction_id']);

$transactions = json_decode((string) file_get_contents($transactionsPath), true);
$history = json_decode((string) file_get_contents($historyPath), true);
$commands = json_decode((string) file_get_contents($commandsPath), true);
$audit = json_decode((string) file_get_contents($auditPath), true);

TestCase::assertSame(1, count($transactions));
TestCase::assertSame(1, count($history));
TestCase::assertSame(1, count($commands));
TestCase::assertSame(1, count($audit));
TestCase::assertSame('transaction.created', $audit[0]['event_type']);

foreach ([$transactionsPath, $historyPath, $idempotencyPath, $commandsPath, $auditPath] as $path) {
    @unlink($path);
}
