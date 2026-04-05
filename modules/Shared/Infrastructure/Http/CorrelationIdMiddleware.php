<?php

declare(strict_types=1);

namespace Modules\Shared\Infrastructure\Http;

use App\Http\Request;

final class CorrelationIdMiddleware
{
    public function handle(Request $request): Request
    {
        if ($request->header('X-Correlation-Id') !== null) {
            return $request;
        }

        $request->headers['X-Correlation-Id'] = $this->uuid();
        return $request;
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}
