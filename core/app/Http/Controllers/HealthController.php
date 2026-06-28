<?php

namespace App\Http\Controllers;

use App\Modules\Health\Services\ReadinessService;
use App\Modules\Proxy\Services\ConfigService;
use App\Modules\Domains\Services\DomainService;
use App\Modules\Dns\Services\DnsService;
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
        return response()->json((new ReadinessService())->index());
    }

    public function readiness(): JsonResponse
    {
        return response()->json((new ReadinessService())->index());
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
            $configService = new ConfigService(new DomainService(), new DnsService());
            $snapshot = $configService->activeSnapshot();
            $checks['config_generation'] = $snapshot === null ? 'warn' : 'ok';
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
