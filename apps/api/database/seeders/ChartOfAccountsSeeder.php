<?php

declare(strict_types=1);

namespace Database\Seeders;

use Modules\Ledger\Domain\Account;
use Modules\Ledger\Infrastructure\Persistence\LedgerRepository;

final class ChartOfAccountsSeeder
{
    /**
     * @var list<array{code:string,name:string,type:string,normal_balance:string}>
     */
    private const ACCOUNT_DEFINITIONS = [
        ['code' => 'merchant_receivable', 'name' => 'Merchant Receivable', 'type' => 'asset', 'normal_balance' => 'debit'],
        ['code' => 'processor_receivable', 'name' => 'Processor Receivable', 'type' => 'asset', 'normal_balance' => 'debit'],
        ['code' => 'bank_settlement', 'name' => 'Bank Settlement', 'type' => 'asset', 'normal_balance' => 'debit'],
        ['code' => 'merchant_payable', 'name' => 'Merchant Payable', 'type' => 'liability', 'normal_balance' => 'credit'],
        ['code' => 'refund_reserve', 'name' => 'Refund Reserve', 'type' => 'liability', 'normal_balance' => 'credit'],
        ['code' => 'processing_fees', 'name' => 'Processing Fees', 'type' => 'revenue', 'normal_balance' => 'credit'],
        ['code' => 'fx_markup', 'name' => 'FX Markup', 'type' => 'revenue', 'normal_balance' => 'credit'],
        ['code' => 'processor_costs', 'name' => 'Processor Costs', 'type' => 'expense', 'normal_balance' => 'debit'],
        ['code' => 'chargeback_losses', 'name' => 'Chargeback Losses', 'type' => 'expense', 'normal_balance' => 'debit'],
    ];

    public function __construct(private readonly LedgerRepository $ledger)
    {
    }

    public function seed(): void
    {
        $timestamp = gmdate(DATE_ATOM);
        $accounts = [];

        foreach (self::ACCOUNT_DEFINITIONS as $definition) {
            $accounts[] = $this->account(
                code: $definition['code'],
                name: $definition['name'],
                type: $definition['type'],
                normalBalance: $definition['normal_balance'],
                timestamp: $timestamp
            );
        }

        $this->ledger->upsertAccounts($accounts);
    }

    private function account(string $code, string $name, string $type, string $normalBalance, string $timestamp): Account
    {
        $existing = $this->ledger->findAccountByCode($code);

        return new Account(
            id: $existing?->id ?? $this->uuid(),
            code: $code,
            name: $name,
            type: $type,
            normalBalance: $normalBalance,
            currency: $existing?->currency,
            createdAt: $existing?->createdAt ?? $timestamp,
            updatedAt: $timestamp
        );
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
