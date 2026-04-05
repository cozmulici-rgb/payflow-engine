<?php

declare(strict_types=1);

namespace Modules\Shared\Interfaces\Http;

use App\Http\Request;
use App\Http\Response;

final class HealthController
{
    public function health(Request $request): Response
    {
        return Response::json([
            'status' => 'ok',
            'service' => 'payflow-engine-api',
            'correlation_id' => $request->header('X-Correlation-Id'),
        ]);
    }

    public function ready(Request $request): Response
    {
        return Response::json([
            'status' => 'ready',
            'checks' => [
                'storage' => 'ok',
            ],
            'correlation_id' => $request->header('X-Correlation-Id'),
        ]);
    }
}
