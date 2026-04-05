<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = []
    ) {
    }

    /**
     * @param array<string,mixed> $body
     */
    public static function json(array $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, $headers);
    }
}
