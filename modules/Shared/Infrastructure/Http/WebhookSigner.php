<?php

declare(strict_types=1);

namespace Modules\Shared\Infrastructure\Http;

final class WebhookSigner
{
    public function sign(string $payload, string $secret, int $timestamp): string
    {
        $signedPayload = 't=' . $timestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        return 't=' . $timestamp . ',v1=' . $signature;
    }
}
