<?php

declare(strict_types=1);

namespace Modules\Ledger\Infrastructure\Persistence;

use Modules\Ledger\Domain\Account;

final class EloquentAccount
{
    /**
     * @param array<string,mixed> $attributes
     */
    public function __construct(public readonly array $attributes)
    {
    }

    public static function fromDomain(Account $account): self
    {
        return new self($account->toArray());
    }

    public function toDomain(): Account
    {
        return Account::fromArray($this->attributes);
    }
}
