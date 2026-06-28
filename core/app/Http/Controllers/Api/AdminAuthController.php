<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ControlPlane\UnixTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = DB::table('admin_users')
            ->where('username', strtolower(trim($validated['username'])))
            ->where('status', 'active')
            ->first();

        if ($user === null || !password_verify((string) $validated['password'], (string) $user->password_hash)) {
            return response()->json(['error' => 'invalid_credentials'], 401);
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = UnixTime::now() + (int) config('cdnlite.admin_session_ttl_seconds', 28800);

        DB::table('admin_sessions')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'created_at' => UnixTime::now(),
            'expires_at' => $expiresAt,
            'revoked_at' => null,
        ]);

        return response()->json([
            'token' => $token,
            'expires_at' => $expiresAt,
            'user' => $this->publicUser((array) $user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->publicUser($request->attributes->get('admin_user'))]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = (string) $request->bearerToken();
        DB::table('admin_sessions')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => UnixTime::now()]);

        return response()->json(['ok' => true]);
    }

    private function publicUser(array $user): array
    {
        return [
            'id' => (string) $user['id'],
            'username' => (string) $user['username'],
            'display_name' => $user['display_name'] ?? null,
            'status' => (string) $user['status'],
            'created_at' => (int) $user['created_at'],
            'updated_at' => (int) $user['updated_at'],
        ];
    }
}
