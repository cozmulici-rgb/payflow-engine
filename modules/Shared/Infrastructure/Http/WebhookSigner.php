<?php

declare(strict_types=1);

namespace Modules\Shared\Infrastructure\Http;

final class WebhookSigner
{
    public function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }
}
