<?php

declare(strict_types=1);

namespace Modules\Ledger\Infrastructure\Persistence;

final class EloquentLedgerEntry
{
    /**
     * @param array<string,mixed> $attributes
     */
    public function __construct(public readonly array $attributes)
    {
    }
}
