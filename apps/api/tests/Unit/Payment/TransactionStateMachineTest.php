<?php

declare(strict_types=1);

use App\Support\TestCase;
use Modules\PaymentProcessing\Domain\TransactionStateMachine;
use Modules\PaymentProcessing\Domain\TransactionStatus;

// Valid transitions
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Pending, TransactionStatus::Authorized));
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Pending, TransactionStatus::Failed));
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Authorized, TransactionStatus::Captured));
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Authorized, TransactionStatus::Voided));
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Authorized, TransactionStatus::Expired));
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Authorized, TransactionStatus::Failed));
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Captured, TransactionStatus::Settled));
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Captured, TransactionStatus::RefundPending));
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::Settled, TransactionStatus::RefundPending));
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::RefundPending, TransactionStatus::Refunded));
TestCase::assertTrue(TransactionStateMachine::canTransition(TransactionStatus::RefundPending, TransactionStatus::Failed));

// Invalid transitions — terminal and backward states
TestCase::assertTrue(!TransactionStateMachine::canTransition(TransactionStatus::Failed, TransactionStatus::Captured), 'Failed should not transition to captured');
TestCase::assertTrue(!TransactionStateMachine::canTransition(TransactionStatus::Failed, TransactionStatus::Authorized), 'Failed is a terminal state');
TestCase::assertTrue(!TransactionStateMachine::canTransition(TransactionStatus::Refunded, TransactionStatus::Captured), 'Refunded is a terminal state');
TestCase::assertTrue(!TransactionStateMachine::canTransition(TransactionStatus::Refunded, TransactionStatus::Authorized), 'Refunded is a terminal state');
TestCase::assertTrue(!TransactionStateMachine::canTransition(TransactionStatus::Pending, TransactionStatus::Captured), 'Pending cannot skip to captured');
TestCase::assertTrue(!TransactionStateMachine::canTransition(TransactionStatus::Captured, TransactionStatus::Authorized), 'Captured cannot go backward to authorized');
