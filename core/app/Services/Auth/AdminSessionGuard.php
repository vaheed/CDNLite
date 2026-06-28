<?php

namespace App\Services\Auth;

use App\Services\ControlPlane\UnixTime;
use Illuminate\Support\Facades\DB;

final class AdminSessionGuard
{
    public function userForToken(?string $token): ?array
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        $row = DB::table('admin_sessions as s')
            ->join('admin_users as u', 'u.id', '=', 's.user_id')
            ->select('u.id', 'u.username', 'u.display_name', 'u.status', 'u.created_at', 'u.updated_at', 's.expires_at')
            ->where('s.token_hash', hash('sha256', $token))
            ->whereNull('s.revoked_at')
            ->where('s.expires_at', '>', UnixTime::now())
            ->where('u.status', 'active')
            ->first();

        return $row === null ? null : (array) $row;
    }
}
