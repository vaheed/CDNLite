<?php

namespace App\Support;

class Response
{
    public static function json(array $payload, int $status = 200): array
    {
        return ['payload' => $payload, 'status' => $status];
    }
}

