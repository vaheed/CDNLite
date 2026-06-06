<?php

namespace App\Modules\Edge\Services;

class EdgeHealthService
{
    public function identityStatus(string $edgeId): string
    {
        return in_array(strtolower(trim($edgeId)), ['', 'unknown', 'openresty'], true)
            ? 'warning'
            : 'ok';
    }
}
