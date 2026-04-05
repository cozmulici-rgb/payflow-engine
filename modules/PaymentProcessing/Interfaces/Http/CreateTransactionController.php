<?php

declare(strict_types=1);

namespace Modules\PaymentProcessing\Interfaces\Http;

use App\Http\Request;
use App\Http\Response;
use Modules\MerchantManagement\Domain\Merchant;
use Modules\MerchantManagement\Infrastructure\Persistence\FileMerchantRepository;
use Modules\PaymentProcessing\Application\CreateTransaction\CreateTransactionCommand;
use Modules\PaymentProcessing\Application\CreateTransaction\CreateTransactionHandler;
use Modules\PaymentProcessing\Interfaces\Http\Requests\CreateTransactionRequest;

final class CreateTransactionController
{
    public function __construct(
        private readonly FileMerchantRepository $merchants,
        private readonly CreateTransactionRequest $requestValidator,
        private readonly CreateTransactionHandler $handler
    ) {
    }

    public function handle(Request $request): Response
    {
        $merchant = $this->authenticate($request);
        if ($merchant === null) {
            return Response::json(['message' => 'Authentication failed'], 401);
        }

        $validated = $this->requestValidator->validate($request, $merchant);
        if ($validated['errors'] !== []) {
            return Response::json([
                'message' => 'Validation failed',
                'errors' => $validated['errors'],
            ], 422);
        }

        $result = $this->handler->handle(new CreateTransactionCommand(
            merchantId: $merchant->id,
            idempotencyKey: (string) $validated['data']['idempotency_key'],
            type: (string) $validated['data']['type'],
            amount: (string) $validated['data']['amount'],
            currency: (string) $validated['data']['currency'],
            settlementCurrency: (string) $validated['data']['settlement_currency'],
            paymentMethodType: (string) $validated['data']['payment_method_type'],
            paymentMethodToken: (string) $validated['data']['payment_method_token'],
            captureMode: (string) $validated['data']['capture_mode'],
            reference: isset($validated['data']['reference']) ? (string) $validated['data']['reference'] : null,
            metadata: is_array($validated['data']['metadata'] ?? null) ? $validated['data']['metadata'] : [],
            correlationId: (string) $request->header('X-Correlation-Id')
        ));

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
