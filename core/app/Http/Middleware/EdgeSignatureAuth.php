<?php

namespace App\Http\Middleware;

use App\Services\ControlPlane\UnixTime;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class EdgeSignatureAuth
{
    private const MAX_CLOCK_SKEW_SECONDS = 120;
    private const NONCE_TTL_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $edgeId = trim((string) $request->header('X-CDNLITE-Edge-Id', ''));
        $timestamp = (int) $request->header('X-CDNLITE-Timestamp', '0');
        $nonce = trim((string) $request->header('X-CDNLITE-Nonce', ''));
        $signature = strtolower(trim((string) $request->header('X-CDNLITE-Signature', '')));

        if ($edgeId === '' || $timestamp === 0 || $nonce === '' || $signature === '') {
            return response()->json(['error' => 'edge_auth_required'], 401);
        }

        if (abs(UnixTime::now() - $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return response()->json(['error' => 'edge_auth_timestamp_out_of_range'], 401);
        }

        if (in_array($request->path(), ['api/v1/edge/register', 'api/v1/edge/heartbeat'], true)
            && $edgeId !== (string) $request->input('edge_id', '')) {
            return response()->json(['error' => 'edge_auth_edge_id_mismatch'], 401);
        }

        $tokenHash = DB::table('edge_tokens')->where('edge_id', $edgeId)->value('token_hash');
        $token = (string) $request->header('Authorization', '');
        $token = Str::startsWith($token, 'Bearer ') ? trim(Str::after($token, 'Bearer ')) : '';

        if (!is_string($tokenHash) || $token === '' || !password_verify($token, $tokenHash)) {
            return response()->json(['error' => 'edge_auth_invalid_token'], 401);
        }

        $canonical = strtoupper($request->method())."\n"
            .'/'.$request->path()."\n"
            .$timestamp."\n"
            .$nonce."\n"
            .hash('sha256', $request->getContent());
        $expected = hash_hmac('sha256', $canonical, hash('sha256', $token));

        if (!hash_equals($expected, $signature)) {
            return response()->json(['error' => 'edge_auth_invalid_signature'], 401);
        }

        DB::table('edge_request_nonces')->where('expires_at', '<', UnixTime::now())->delete();

        $inserted = DB::table('edge_request_nonces')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'edge_id' => $edgeId,
            'nonce' => $nonce,
            'created_at' => UnixTime::now(),
            'expires_at' => UnixTime::now() + self::NONCE_TTL_SECONDS,
        ]);

        if ($inserted !== 1) {
            return response()->json(['error' => 'edge_auth_replay_detected'], 409);
        }

        $request->attributes->set('edge_id', $edgeId);

        return $next($request);
    }
}
