<?php

declare(strict_types=1);

namespace Modules\MerchantManagement\Interfaces\Http;

use App\Http\Request;
use App\Http\Response;
use Modules\MerchantManagement\Application\IssueApiCredential\IssueApiCredentialHandler;

final class IssueApiCredentialController
{
    public function __construct(private readonly IssueApiCredentialHandler $handler)
    {
    }

    public function handle(Request $request): Response
    {
        $operatorId = $request->header('X-Operator-Id');
        $operatorRole = $request->header('X-Operator-Role');
        if ($operatorId === null || $operatorRole === null) {
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
