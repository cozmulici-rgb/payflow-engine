<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Interfaces\Http;

use App\Http\Request;
use App\Http\Response;
use Modules\MerchantManagement\Application\CreateMerchant\CreateMerchantCommand;
use Modules\MerchantManagement\Application\CreateMerchant\CreateMerchantHandler;

final class CreateMerchantController
{
    public function __construct(
        private readonly CreateMerchantHandler $handler,
        private readonly string $operatorSecret
    ) {
    }

    public function handle(Request $request): Response
    {
        $operatorId = $request->header('X-Operator-Id');
        $operatorRole = $request->header('X-Operator-Role');
        $providedSecret = $request->header('X-Operator-Secret');
        if ($operatorId === null || $operatorRole === null || $providedSecret === null) {
            return Response::json(['message' => 'Authentication failed'], 401);
        }
        if (!hash_equals($this->operatorSecret, $providedSecret)) {
            return Response::json(['message' => 'Authentication failed'], 401);
        }
        if ($operatorRole !== 'merchant.write') {
            return Response::json(['message' => 'Forbidden'], 403);
        }

        foreach (['legal_name', 'display_name', 'country', 'default_currency'] as $field) {
            if (!isset($request->body[$field]) || $request->body[$field] === '') {
                return Response::json(['message' => "Validation failed: {$field} is required"], 422);
            }
        }

        $merchant = $this->handler->handle(new CreateMerchantCommand(
            legalName: (string) $request->body['legal_name'],
            displayName: (string) $request->body['display_name'],
            country: (string) $request->body['country'],
            defaultCurrency: (string) $request->body['default_currency'],
            actorId: $operatorId,
            correlationId: (string) $request->header('X-Correlation-Id')
        ));

        return Response::json([
            'data' => [
                'merchant_id' => $merchant->id,
                'status' => $merchant->status,
            ],
        ], 201);
    }
}
