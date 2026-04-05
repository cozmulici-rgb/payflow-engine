<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed> $body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public array $headers = [],
        public array $body = []
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
