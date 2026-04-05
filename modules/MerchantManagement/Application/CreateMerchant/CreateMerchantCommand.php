<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Application\CreateMerchant;

final class CreateMerchantCommand
{
    public function __construct(
        public readonly string $legalName,
        public readonly string $displayName,
        public readonly string $country,
        public readonly string $defaultCurrency,
        public readonly string $actorId,
        public readonly string $correlationId
    ) {
    }
}
