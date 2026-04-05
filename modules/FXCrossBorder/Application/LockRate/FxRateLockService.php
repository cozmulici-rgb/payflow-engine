<?php

declare(strict_types=1);

namespace Modules\FXCrossBorder\Application\LockRate;

use Modules\FXCrossBorder\Infrastructure\Persistence\RateLockRepository;

final class FxRateLockService
{
    public function __construct(
        private readonly RateLockRepository $locks,
        private readonly string $configPath
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function lock(FxQuoteRequest $request): array
    {
        $config = require $this->configPath;
        $key = strtoupper($request->baseCurrency) . ':' . strtoupper($request->quoteCurrency);
        $rate = (string) ($config['fx']['default_rates'][$key] ?? '1.00000000');
        $settlementAmount = number_format((float) $request->amount * (float) $rate, 4, '.', '');

        return $this->locks->create([
            'transaction_id' => $request->transactionId,
            'base_currency' => strtoupper($request->baseCurrency),
            'quote_currency' => strtoupper($request->quoteCurrency),
            'rate' => $rate,
            'settlement_amount' => $settlementAmount,
            'expires_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify('+' . (int) ($config['fx']['lock_ttl_seconds'] ?? 1800) . ' seconds')
                ->format(DATE_ATOM),
        ]);
    }

    public function markUsed(string $rateLockId): void
    {
        $this->locks->markUsed($rateLockId);
    }
}
