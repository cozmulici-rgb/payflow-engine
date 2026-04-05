<?php

declare(strict_types=1);

namespace Modules\Ledger\Application;

use Modules\Ledger\Domain\JournalEntry;
use Modules\Ledger\Infrastructure\Persistence\LedgerRepository;
use Modules\PaymentProcessing\Domain\Transaction;

final class PostAuthorizationLedgerEntries implements LedgerPostingService
{
    public function __construct(private readonly LedgerRepository $ledger)
    {
    }

    public function postAuthorization(Transaction $transaction): string
    {
        $processorReceivable = $this->requireAccountId('processor_receivable');
        $merchantPayable = $this->requireAccountId('merchant_payable');
        $currency = strtoupper($transaction->settlementCurrency !== '' ? $transaction->settlementCurrency : $transaction->currency);
        $amount = $this->normalizeAmount($transaction->settlementAmount ?? $transaction->amount);
        $journalEntry = new JournalEntry(
            id: $this->uuid(),
            referenceType: 'transaction.authorization',
            referenceId: $transaction->id,
            description: sprintf('Authorization hold for transaction %s', $transaction->id),
            effectiveDate: substr($transaction->updatedAt, 0, 10),
            createdAt: gmdate(DATE_ATOM)
        );

        $this->ledger->appendJournalEntry($journalEntry, [
            [
                'account_id' => $processorReceivable,
                'entry_type' => 'debit',
                'amount' => $amount,
                'currency' => $currency,
                'transaction_id' => $transaction->id,
                'settlement_batch_id' => null,
                'description' => $journalEntry->description,
                'effective_date' => $journalEntry->effectiveDate,
                'created_at' => $journalEntry->createdAt,
            ],
            [
                'account_id' => $merchantPayable,
                'entry_type' => 'credit',
                'amount' => $amount,
                'currency' => $currency,
                'transaction_id' => $transaction->id,
                'settlement_batch_id' => null,
                'description' => $journalEntry->description,
                'effective_date' => $journalEntry->effectiveDate,
                'created_at' => $journalEntry->createdAt,
            ],
        ]);

        return $journalEntry->id;
    }

    private function requireAccountId(string $code): string
    {
        $account = $this->ledger->findAccountByCode($code);
        if ($account === null) {
            throw new \RuntimeException(sprintf('Ledger account [%s] is not configured', $code));
        }

        return $account->id;
    }

    private function normalizeAmount(string $amount): string
    {
        return number_format((float) $amount, 4, '.', '');
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
