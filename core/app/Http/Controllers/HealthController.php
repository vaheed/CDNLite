<?php

namespace App\Http\Controllers;

use App\Services\ControlPlane\ReadinessService;
use App\Support\ApiAuth;
use App\Support\Database;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'time' => time(),
        ]);
    }

    public function cdnHealth(): JsonResponse
    {
        return response()->json((new ReadinessService())->check());
    }

    public function readiness(): JsonResponse
    {
        return response()->json((new ReadinessService())->check());
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'postgres' => 'ok',
            'schema' => 'ok',
            'config_generation' => 'ok',
        ];

        try {
            Database::pdo()->query('SELECT 1');
        } catch (\Throwable) {
            $checks['postgres'] = 'fail';
        }

        try {
            $required = ['domains', 'redirect_rules', 'rate_limit_rules', 'waf_rules', 'cache_rules', 'config_state', 'config_snapshots'];
            foreach ($required as $table) {
                $stmt = Database::pdo()->query("SELECT to_regclass('public." . $table . "')");
                if ($stmt->fetchColumn() === null) {
                    $checks['schema'] = 'fail';
                    break;
                }
            }
        } catch (\Throwable) {
            $checks['schema'] = 'fail';
        }

        try {
            $activeVersion = Database::pdo()->query('SELECT active_snapshot_version FROM config_state WHERE id = 1')->fetchColumn();
            $checks['config_generation'] = $activeVersion === false || $activeVersion === null ? 'warn' : 'ok';
        } catch (\Throwable) {
            $checks['config_generation'] = 'fail';
        }

        if (ApiAuth::productionMissingToken()) {
            $checks['api_token'] = 'fail';
        } else {
            $checks['api_token'] = ApiAuth::isConfigured() ? 'ok' : 'warn';
        }

        $ok = !in_array('fail', $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'fail',
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }
}
