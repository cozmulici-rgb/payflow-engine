<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Infrastructure\Providers\Fraud;

use Modules\PaymentProcessing\Domain\Transaction;

final class FraudScreeningService
{
    /**
     * @return array{approved:bool,reason:string}
     */
    public function screen(Transaction $transaction): array
    {
        $channel = (string) ($transaction->metadata['channel'] ?? '');

        if ($channel === 'fraud') {
            return [
                'approved' => false,
                'reason' => 'Risk score above merchant threshold',
            ];
        }

        return [
            'approved' => true,
            'reason' => 'approved',
        ];
    }
}
