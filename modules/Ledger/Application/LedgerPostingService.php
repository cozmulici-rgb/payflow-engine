<?php

declare(strict_types=1);

namespace Modules\Ledger\Application;

use Modules\PaymentProcessing\Domain\Transaction;

interface LedgerPostingService
{
    public function postAuthorization(Transaction $transaction): string;

    public function postRefund(Transaction $transaction): string;
}
