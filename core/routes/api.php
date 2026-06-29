<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\CollectorController;
use App\Http\Controllers\Api\DnsOperationsController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\EdgeController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\HealthController;
use App\Modules\Settings\Http\Controllers\SettingsController as PlatformSettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/v1/readiness', [HealthController::class, 'readiness']);
Route::post('/v1/admin/login', [AdminAuthController::class, 'login']);

Route::middleware('admin.auth')->prefix('/v1')->group(function (): void {
    Route::get('/admin/me', [AdminAuthController::class, 'me']);
    Route::post('/admin/logout', [AdminAuthController::class, 'logout']);

    Route::get('/overview', [OperationsController::class, 'overview']);
    Route::get('/settings', static fn (PlatformSettingsController $settings) => response()->json($settings->index()));
    Route::get('/settings/{group}', static function (string $group, PlatformSettingsController $settings) {
        try {
            return response()->json($settings->show($group));
        } catch (\InvalidArgumentException $error) {
            return response()->json(['error' => $error->getMessage()], 404);
        }
    });
    Route::patch('/settings/{group}', static function (string $group, Request $request, PlatformSettingsController $settings) {
        try {
            $admin = $request->attributes->get('admin_user');
            return response()->json($settings->update($group, $request->all(), $admin['username'] ?? null));
        } catch (\InvalidArgumentException $error) {
            $status = $error->getMessage() === 'settings_group_not_found' ? 404 : 422;
            return response()->json(['error' => $error->getMessage()], $status);
        }
    });
    Route::post('/settings/validate', static function (Request $request, PlatformSettingsController $settings) {
        try {
            return response()->json($settings->validate($request->all()));
        } catch (\InvalidArgumentException $error) {
            $status = $error->getMessage() === 'settings_group_not_found' ? 404 : 422;
            return response()->json(['error' => $error->getMessage()], $status);
        }
    });
    Route::post('/settings/test/powerdns', static fn (PlatformSettingsController $settings) => response()->json($settings->testPowerDns()));
    Route::get('/audit', [OperationsController::class, 'audit']);

    Route::get('/domains', [DomainController::class, 'index']);
    Route::post('/domains', [DomainController::class, 'store']);
    Route::get('/domains/{domainId}', [DomainController::class, 'show']);
    Route::patch('/domains/{domainId}', [DomainController::class, 'update']);
    Route::delete('/domains/{domainId}', [DomainController::class, 'destroy']);
    Route::post('/domains/{domainId}/nameservers/verify', [DomainController::class, 'verifyNameservers']);
    Route::post('/domains/{domainId}/nameservers/force-verify', [DomainController::class, 'forceVerifyNameservers']);
    Route::post('/domains/{domainId}/nameservers/reseed-expected', [DomainController::class, 'reseedExpectedNameservers']);
    Route::post('/domains/{domainId}/activate', [DomainController::class, 'activate']);
    Route::get('/domains/{domainId}/dns/status', [DomainController::class, 'dnsStatus']);
    Route::get('/domains/{domainId}/dns/records', [DomainController::class, 'dnsRecords']);
    Route::post('/domains/{domainId}/dns/records', [DomainController::class, 'storeDnsRecord']);
    Route::get('/domains/{domainId}/dns/records/{recordId}', [DomainController::class, 'showDnsRecord']);
    Route::patch('/domains/{domainId}/dns/records/{recordId}', [DomainController::class, 'updateDnsRecord']);
    Route::delete('/domains/{domainId}/dns/records/{recordId}', [DomainController::class, 'destroyDnsRecord']);
    Route::post('/domains/{domainId}/dns/records/{recordId}/reconcile', [DomainController::class, 'reconcileDnsRecord']);
    Route::get('/domains/{domainId}/origins', [DomainController::class, 'origins']);
    Route::post('/domains/{domainId}/origins', [DomainController::class, 'storeOrigin']);
    Route::get('/domains/{domainId}/origins/health', [DomainController::class, 'originHealth']);
    Route::post('/domains/{domainId}/origins/{originId}/check', [DomainController::class, 'checkOrigin']);
    Route::post('/domains/{domainId}/origins/{originId}/test', [DomainController::class, 'testOrigin']);
    Route::patch('/domains/{domainId}/origins/{originId}', [DomainController::class, 'updateOrigin']);
    Route::delete('/domains/{domainId}/origins/{originId}', [DomainController::class, 'destroyOrigin']);

    Route::get('/dns/operations', [DnsOperationsController::class, 'status']);
    Route::get('/dns/zones', [DnsOperationsController::class, 'zones']);
    Route::get('/dns/zones/{zone}/actual', [DnsOperationsController::class, 'actual']);
    Route::get('/dns/desired', [DnsOperationsController::class, 'desired']);
    Route::post('/dns/dry-run', [DnsOperationsController::class, 'dryRun']);
    Route::post('/dns/force-sync', [DnsOperationsController::class, 'forceSync']);

    Route::get('/edge/nodes', [EdgeController::class, 'index']);
    Route::get('/edges/dns', [EdgeController::class, 'dns']);
});

Route::middleware('edge.auth')->prefix('/v1')->group(function (): void {
    Route::post('/edge/register', [EdgeController::class, 'register']);
    Route::post('/edge/heartbeat', [EdgeController::class, 'heartbeat']);
    Route::get('/edge/config', [EdgeController::class, 'config']);
    Route::post('/collector/usage', [CollectorController::class, 'usage']);
});
