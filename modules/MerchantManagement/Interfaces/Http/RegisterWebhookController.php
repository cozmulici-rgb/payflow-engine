<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Interfaces\Http;

use App\Http\Request;
use App\Http\Response;
use Modules\MerchantManagement\Domain\Merchant;
use Modules\MerchantManagement\Application\RegisterWebhook\RegisterWebhookHandler;
use Modules\MerchantManagement\Infrastructure\Persistence\FileMerchantRepository;

final class RegisterWebhookController
{
    public function __construct(
        private readonly FileMerchantRepository $merchants,
        private readonly RegisterWebhookHandler $handler
    ) {
    }

    public function handle(Request $request): Response
    {
        $merchant = $this->authenticate($request);
        if ($merchant === null) {
            return Response::json(['message' => 'Authentication failed'], 401);
        }

        $eventTypes = $request->body['event_types'] ?? [];
        $result = $this->handler->handle(
            $merchant->id,
            (string) ($request->body['url'] ?? ''),
            is_array($eventTypes) ? array_values(array_map('strval', $eventTypes)) : [],
            (string) $request->header('X-Correlation-Id')
        );

        return Response::json($result['body'], $result['status']);
    }

    private function authenticate(Request $request): ?Merchant
    {
        $merchantId = $request->header('X-Merchant-Id');
        $keyId = $request->header('X-Merchant-Key-Id');
        $secret = $request->header('X-Merchant-Secret');

        if ($merchantId === null || $keyId === null || $secret === null) {
            return null;
        }

        return $this->merchants->verifyCredential($merchantId, $keyId, $secret);
    }
}
