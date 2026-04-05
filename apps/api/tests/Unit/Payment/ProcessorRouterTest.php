<?php

declare(strict_types=1);

use App\Support\TestCase;
use Modules\PaymentProcessing\Domain\Transaction;
use Modules\PaymentProcessing\Domain\TransactionStatus;
use Modules\PaymentProcessing\Infrastructure\Providers\Processor\ProcessorRouter;

$router = new ProcessorRouter();

$transactionA = new Transaction(
    id: 'trx-a',
    merchantId: 'm-1',
    idempotencyKey: 'idem-a',
    type: 'authorization',
    amount: '10.00',
    currency: 'CAD',
    settlementCurrency: 'CAD',
    paymentMethodType: 'card_token',
    paymentMethodToken: 'tok-a',
    captureMode: 'manual',
    reference: null,
    status: TransactionStatus::Pending,
    processorId: null,
    processorReference: null,
    settlementAmount: null,
    fxRateLockId: null,
    errorCode: null,
    errorMessage: null,
    metadata: ['channel' => 'processor_b'],
    createdAt: gmdate(DATE_ATOM),
    updatedAt: gmdate(DATE_ATOM)
);

$processor = $router->route($transactionA);
$result = $processor->authorize($transactionA, null);
TestCase::assertSame('processor_b', $result->processorId);

$transactionB = new Transaction(
    id: 'trx-b',
    merchantId: 'm-1',
    idempotencyKey: 'idem-b',
    type: 'authorization',
    amount: '10.00',
    currency: 'CAD',
    settlementCurrency: 'CAD',
    paymentMethodType: 'card_token',
    paymentMethodToken: 'tok-b',
    captureMode: 'manual',
    reference: null,
    status: TransactionStatus::Pending,
    processorId: null,
    processorReference: null,
    settlementAmount: null,
    fxRateLockId: null,
    errorCode: null,
    errorMessage: null,
    metadata: [],
    createdAt: gmdate(DATE_ATOM),
    updatedAt: gmdate(DATE_ATOM)
);

TestCase::assertSame('processor_a', $router->route($transactionB)->authorize($transactionB, null)->processorId);
