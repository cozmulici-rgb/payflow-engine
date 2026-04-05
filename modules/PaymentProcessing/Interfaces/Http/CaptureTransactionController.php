<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Interfaces\Http;

use App\Http\Request;
use App\Http\Response;
use Modules\MerchantManagement\Domain\Merchant;
use Modules\MerchantManagement\Infrastructure\Persistence\FileMerchantRepository;
use Modules\PaymentProcessing\Application\CaptureTransaction\CaptureTransactionHandler;

final class CaptureTransactionController
{
    public function __construct(
        private readonly FileMerchantRepository $merchants,
        private readonly CaptureTransactionHandler $handler
    ) {
    }

    public function handle(Request $request): Response
    {
        $merchant = $this->authenticate($request);
        if ($merchant === null) {
            return Response::json(['message' => 'Authentication failed'], 401);
        }

        $transactionId = (string) $request->routeParam('transaction_id');
        $amount = (string) ($request->body['amount'] ?? '');

        $result = $this->handler->handle(
            $merchant->id,
            $transactionId,
            $amount,
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
