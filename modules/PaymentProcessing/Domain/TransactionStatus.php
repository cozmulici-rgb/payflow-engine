<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Domain;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Settled = 'settled';
    case Failed = 'failed';
    case Voided = 'voided';
    case Expired = 'expired';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
}
