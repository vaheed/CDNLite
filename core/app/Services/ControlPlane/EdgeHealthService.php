<?php

namespace App\Services\ControlPlane;

class EdgeHealthService
{
    public function identityStatus(string $edgeId): string
    {
        return in_array(strtolower(trim($edgeId)), ['', 'unknown', 'openresty'], true)
            ? 'warning'
            : 'ok';
    }
}
