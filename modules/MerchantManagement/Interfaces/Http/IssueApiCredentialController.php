<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Interfaces\Http;

use App\Http\Request;
use App\Http\Response;
use Modules\MerchantManagement\Application\IssueApiCredential\IssueApiCredentialHandler;

final class IssueApiCredentialController
{
    public function __construct(
        private readonly IssueApiCredentialHandler $handler,
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
        if (!isset($request->body['merchant_id']) || $request->body['merchant_id'] === '') {
            return Response::json(['message' => 'Validation failed: merchant_id is required'], 422);
        }

        try {
            $credential = $this->handler->handle(
                (string) $request->body['merchant_id'],
                $operatorId,
                (string) $request->header('X-Correlation-Id')
            );
        } catch (\RuntimeException $e) {
            return Response::json(['message' => $e->getMessage()], 404);
        }

        return Response::json(['data' => $credential], 201);
    }
}
