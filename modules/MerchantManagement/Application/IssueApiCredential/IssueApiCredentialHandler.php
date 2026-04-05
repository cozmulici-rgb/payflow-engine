<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Application\IssueApiCredential;

use Modules\Audit\Application\WriteAuditRecord;
use Modules\MerchantManagement\Infrastructure\Persistence\FileMerchantRepository;

final class IssueApiCredentialHandler
{
    public function __construct(
        private readonly FileMerchantRepository $repository,
        private readonly WriteAuditRecord $writeAuditRecord
    ) {
    }

    public function handle(string $merchantId, string $actorId, string $correlationId): array
    {
        $merchant = $this->repository->find($merchantId);
        if ($merchant === null) {
            throw new \RuntimeException('Merchant not found');
        }

        $keyId = 'pk_' . bin2hex(random_bytes(6));
        $secret = 'sk_' . bin2hex(random_bytes(12));
        $this->repository->addCredential($merchantId, $keyId, password_hash($secret, PASSWORD_DEFAULT));

        $this->writeAuditRecord->handle([
            'event_type' => 'merchant.api_credential_issued',
            'actor_id' => $actorId,
            'action' => 'issue_credential',
            'resource_type' => 'merchant',
            'resource_id' => $merchantId,
            'correlation_id' => $correlationId,
            'context' => [
                'key_id' => $keyId,
            ],
        ]);

        return [
            'merchant_id' => $merchantId,
            'key_id' => $keyId,
            'secret' => $secret,
        ];
    }
}
