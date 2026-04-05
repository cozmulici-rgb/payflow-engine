<?php

declare(strict_types=1);

namespace Modules\Settlement\Infrastructure\Storage;

final class SettlementArtifactStore
{
    public function __construct(
        private readonly string $artifactsPath,
        private readonly bool $failWrites = false
    ) {
    }

    public function store(string $batchId, string $contents): string
    {
        if ($this->failWrites) {
            throw new \RuntimeException('Settlement artifact storage failed');
        }

        if (!is_dir($this->artifactsPath)) {
            mkdir($this->artifactsPath, 0777, true);
        }

        $key = 'settlement/' . $batchId . '.csv';
        $path = $this->artifactsPath . '/' . basename($batchId) . '.csv';
        file_put_contents($path, $contents);

        return $key;
    }
}
