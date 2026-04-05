<?php

declare(strict_types=1);

namespace Modules\Ledger\Infrastructure\Persistence;

use Modules\Ledger\Domain\Account;
use Modules\Ledger\Domain\JournalEntry;

final class LedgerRepository
{
    public function __construct(
        private readonly string $accountsPath,
        private readonly string $journalEntriesPath,
        private readonly string $ledgerEntriesPath
    ) {
    }

    public function findAccountByCode(string $code): ?Account
    {
        foreach ($this->readJson($this->accountsPath) as $row) {
            if (($row['code'] ?? null) === $code) {
                return (new EloquentAccount($row))->toDomain();
            }
        }

        return null;
    }

    /**
     * @param list<Account> $accounts
     */
    public function upsertAccounts(array $accounts): void
    {
        $current = [];
        foreach ($this->readJson($this->accountsPath) as $row) {
            $current[(string) ($row['code'] ?? '')] = $row;
        }

        foreach ($accounts as $account) {
            $current[$account->code] = EloquentAccount::fromDomain($account)->attributes;
        }

        ksort($current);
        $this->writeJson($this->accountsPath, array_values($current));
    }

    /**
     * @param list<array<string,mixed>> $entries
     */
    public function appendJournalEntry(JournalEntry $journalEntry, array $entries): void
    {
        $this->assertBalanced($entries);
        $this->assertKnownAccounts($entries);

        $snapshots = $this->captureSnapshots([$this->journalEntriesPath, $this->ledgerEntriesPath]);

        try {
            $journals = $this->readJson($this->journalEntriesPath);
            $journals[] = $journalEntry->toArray();

            $ledger = $this->readJson($this->ledgerEntriesPath);
            $nextId = $this->nextLedgerId($ledger);

            foreach ($entries as $entry) {
                $entry['id'] = $nextId++;
                $entry['journal_entry_id'] = $journalEntry->id;
                $ledger[] = (new EloquentLedgerEntry($entry))->attributes;
            }

            $this->writeJson($this->journalEntriesPath, $journals);
            $this->writeJson($this->ledgerEntriesPath, $ledger);
        } catch (\Throwable $exception) {
            $this->restoreSnapshots($snapshots);
            throw $exception;
        }
    }

    /**
     * @param list<array<string,mixed>> $entries
     */
    private function assertBalanced(array $entries): void
    {
        if (count($entries) !== 2) {
            throw new \RuntimeException('Authorization postings must contain exactly two ledger entries');
        }

        $types = array_map(static fn (array $entry): string => (string) ($entry['entry_type'] ?? ''), $entries);
        sort($types);
        if ($types !== ['credit', 'debit']) {
            throw new \RuntimeException('Authorization postings must contain one debit and one credit');
        }

        $amounts = array_map(
            fn (array $entry): string => $this->normalizeAmount((string) ($entry['amount'] ?? '0')),
            $entries
        );

        if ($amounts[0] !== $amounts[1]) {
            throw new \RuntimeException('Authorization postings must be balanced');
        }
    }

    /**
     * @param list<array<string,mixed>> $entries
     */
    private function assertKnownAccounts(array $entries): void
    {
        $knownAccounts = [];
        foreach ($this->readJson($this->accountsPath) as $account) {
            $knownAccounts[(string) ($account['id'] ?? '')] = true;
        }

        foreach ($entries as $entry) {
            $accountId = (string) ($entry['account_id'] ?? '');
            if (!isset($knownAccounts[$accountId])) {
                throw new \RuntimeException(sprintf('Ledger account [%s] does not exist', $accountId));
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $ledger
     */
    private function nextLedgerId(array $ledger): int
    {
        $max = 0;
        foreach ($ledger as $entry) {
            $max = max($max, (int) ($entry['id'] ?? 0));
        }

        return $max + 1;
    }

    /**
     * @param list<string> $paths
     * @return array<string,string|null>
     */
    private function captureSnapshots(array $paths): array
    {
        $snapshots = [];
        foreach ($paths as $path) {
            $snapshots[$path] = is_file($path) ? (string) file_get_contents($path) : null;
        }

        return $snapshots;
    }

    /**
     * @param array<string,string|null> $snapshots
     */
    private function restoreSnapshots(array $snapshots): void
    {
        foreach ($snapshots as $path => $contents) {
            if ($contents === null) {
                if (is_file($path)) {
                    unlink($path);
                }

                continue;
            }

            file_put_contents($path, $contents);
        }
    }

    private function normalizeAmount(string $amount): string
    {
        return number_format((float) $amount, 4, '.', '');
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeJson(string $path, array $payload): void
    {
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
