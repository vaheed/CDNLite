<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CdnliteCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        $origin = trim((string) $request->headers->get('Origin', ''));
        if ($origin === '') {
            return $response;
        }

        $allowed = $this->allowedOrigins();
        if (!in_array('*', $allowed, true) && !in_array($origin, $allowed, true)) {
            return $response;
        }

        $response->headers->set('Access-Control-Allow-Origin', in_array('*', $allowed, true) ? '*' : $origin);
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, X-CDNLITE-Edge-Id, X-CDNLITE-Timestamp, X-CDNLITE-Nonce, X-CDNLITE-Signature');
        $response->headers->set('Access-Control-Max-Age', '600');

        return $response;
    }

    private function allowedOrigins(): array
    {
        $raw = (string) config('cdnlite.cors_allowed_origins', 'http://localhost:8082,http://127.0.0.1:8082');

        return array_values(array_filter(
            array_map(static fn (string $item): string => trim($item), explode(',', $raw)),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
