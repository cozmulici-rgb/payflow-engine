<?php

declare(strict_types=1);

namespace Modules\Ledger\Domain;

final class JournalEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $referenceType,
        public readonly string $referenceId,
        public readonly string $description,
        public readonly string $effectiveDate,
        public readonly string $createdAt
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'description' => $this->description,
            'effective_date' => $this->effectiveDate,
            'created_at' => $this->createdAt,
        ];
    }
}
