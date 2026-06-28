<?php

namespace App\Http\Middleware;

use App\Services\Auth\AdminSessionGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminBearerAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $user = app(AdminSessionGuard::class)->userForToken($token);

        if ($user === null) {
            return response()->json(['error' => 'admin_auth_required'], 401);
        }

        $request->attributes->set('admin_user', $user);

        return $next($request);
    }
}
