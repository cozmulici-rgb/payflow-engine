<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Application\CreateMerchant;

use Modules\Audit\Application\WriteAuditRecord;
use Modules\MerchantManagement\Domain\Merchant;
use Modules\MerchantManagement\Infrastructure\Persistence\FileMerchantRepository;

final class CreateMerchantHandler
{
    public function __construct(
        private readonly FileMerchantRepository $repository,
        private readonly WriteAuditRecord $writeAuditRecord
    ) {
    }

    public function handle(CreateMerchantCommand $command): Merchant
    {
        $merchant = new Merchant(
            id: $this->uuid(),
            legalName: $command->legalName,
            displayName: $command->displayName,
            country: strtoupper($command->country),
            defaultCurrency: strtoupper($command->defaultCurrency),
            status: 'active',
            createdAt: gmdate(DATE_ATOM)
        );

        $this->repository->save($merchant);

        $this->writeAuditRecord->handle([
            'event_type' => 'merchant.created',
            'actor_id' => $command->actorId,
            'action' => 'create',
            'resource_type' => 'merchant',
            'resource_id' => $merchant->id,
            'correlation_id' => $command->correlationId,
            'context' => [
                'display_name' => $merchant->displayName,
                'country' => $merchant->country,
            ],
        ]);

        return $merchant;
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
