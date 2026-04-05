<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Domain;

final class TransactionStateMachine
{
    public static function canTransition(TransactionStatus $from, TransactionStatus $to): bool
    {
        $allowed = [
            TransactionStatus::Pending->value => [
                TransactionStatus::Authorized->value,
                TransactionStatus::Failed->value,
            ],
            TransactionStatus::Authorized->value => [
                TransactionStatus::Captured->value,
                TransactionStatus::Failed->value,
                TransactionStatus::Voided->value,
                TransactionStatus::Expired->value,
            ],
            TransactionStatus::Captured->value => [
                TransactionStatus::Settled->value,
                TransactionStatus::RefundPending->value,
            ],
            TransactionStatus::Settled->value => [
                TransactionStatus::RefundPending->value,
            ],
            TransactionStatus::RefundPending->value => [
                TransactionStatus::Refunded->value,
                TransactionStatus::Failed->value,
            ],
        ];

        return in_array($to->value, $allowed[$from->value] ?? [], true);
    }
}
