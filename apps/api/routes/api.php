<?php

declare(strict_types=1);

use App\Http\Request;
use App\Support\Application;

function api_routes(): array
{
    return [
        'GET' => [
            '/internal/health' => static fn (Request $request, Application $app) => $app->healthController()->health($request),
            '/internal/ready' => static fn (Request $request, Application $app) => $app->healthController()->ready($request),
        ],
        'POST' => [
            '/internal/v1/merchants' => static fn (Request $request, Application $app) => $app->createMerchantController()->handle($request),
            '/internal/v1/merchants/credentials' => static fn (Request $request, Application $app) => $app->issueApiCredentialController()->handle($request),
        ],
    ];
}
