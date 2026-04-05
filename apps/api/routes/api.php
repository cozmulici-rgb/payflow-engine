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
            '/v1/transactions/{transaction_id}' => static fn (Request $request, Application $app) => $app->getTransactionController()->handle($request),
        ],
        'POST' => [
            '/internal/v1/merchants' => static fn (Request $request, Application $app) => $app->createMerchantController()->handle($request),
            '/internal/v1/merchants/credentials' => static fn (Request $request, Application $app) => $app->issueApiCredentialController()->handle($request),
            '/v1/transactions' => static fn (Request $request, Application $app) => $app->createTransactionController()->handle($request),
        ],
    ];
}
