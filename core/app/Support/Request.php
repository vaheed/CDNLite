<?php

namespace App\Support;

class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly string $rawBody
    ) {}
}

