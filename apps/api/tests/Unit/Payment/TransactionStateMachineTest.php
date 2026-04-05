<?php

declare(strict_types=1);

use App\Support\TestCase;
use Modules\PaymentProcessing\Domain\TransactionStateMachine;
use Modules\PaymentProcessing\Domain\TransactionStatus;

TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Pending, TransactionStatus::Authorized));
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Pending, TransactionStatus::Failed));
TestCase::assertTrue(!TransactionStateMachine::canTransition(TransactionStatus::Failed, TransactionStatus::Captured), 'Failed should not transition to captured');
