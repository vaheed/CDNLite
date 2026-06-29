<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\CollectorController;
use App\Http\Controllers\Api\DnsOperationsController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\EdgeController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\HealthController;
use App\Modules\Proxy\Http\Controllers\TrafficRulesController;
use App\Modules\Proxy\Services\TrafficRulesService;
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
    })->where('group', '.*');
    Route::patch('/settings/{group}', static function (string $group, Request $request, PlatformSettingsController $settings) {
        try {
            $admin = $request->attributes->get('admin_user');
            return response()->json($settings->update($group, $request->all(), $admin['username'] ?? null));
        } catch (\InvalidArgumentException $error) {
            $status = $error->getMessage() === 'settings_group_not_found' ? 404 : 422;
            return response()->json(['error' => $error->getMessage()], $status);
        }
    })->where('group', '.*');
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
    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/traffic', [ReportController::class, 'traffic']);
    Route::get('/reports/cache', [ReportController::class, 'cache']);
    Route::get('/reports/edge', [ReportController::class, 'edge']);
    Route::get('/reports/security', [ReportController::class, 'security']);
    Route::get('/reports/reliability', [ReportController::class, 'reliability']);
    Route::get('/reports/operations', [ReportController::class, 'operations']);
    Route::get('/recommendations', [ReportController::class, 'listRecommendations']);
    Route::post('/recommendations/generate', [ReportController::class, 'generateRecommendations']);
    Route::get('/usage/summary', [CollectorController::class, 'usageSummary']);
    Route::post('/usage/recalculate', [CollectorController::class, 'recalculateUsage']);
    Route::get('/usage/recalculate/{jobId}', [CollectorController::class, 'recalculateJob']);
    Route::get('/analytics/cache', [CollectorController::class, 'cacheAnalytics']);
    Route::get('/security/events', [CollectorController::class, 'securityEventList']);
    Route::get('/security/summary', [CollectorController::class, 'securitySummary']);

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
    Route::get('/domains/{domainId}/analytics/summary', [CollectorController::class, 'domainUsageSummary']);
    Route::get('/domains/{domainId}/analytics/cache', [CollectorController::class, 'domainCacheAnalytics']);
    Route::get('/domains/{domainId}/activity', [CollectorController::class, 'activityTimeline']);
    Route::get('/domains/{domainId}/activity/summary', [CollectorController::class, 'activitySummary']);
    Route::get('/domains/{domainId}/activity/requests', [CollectorController::class, 'recentRequests']);
    Route::get('/domains/{domainId}/activity/requests/{requestId}', [CollectorController::class, 'findRequest']);
    Route::get('/domains/{domainId}/activity/export', [CollectorController::class, 'activityExport']);
    Route::get('/domains/{domainId}/security/events', [CollectorController::class, 'domainSecurityEvents']);
    Route::get('/domains/{domainId}/recommendations', [ReportController::class, 'listRecommendations']);
    Route::post('/domains/{domainId}/recommendations/generate', [ReportController::class, 'generateRecommendations']);
    Route::post('/domains/{domainId}/recommendations/{recommendationId}/apply', [ReportController::class, 'applyRecommendation']);
    Route::post('/domains/{domainId}/recommendations/{recommendationId}/dismiss', [ReportController::class, 'dismissRecommendation']);
    Route::post('/domains/{domainId}/recommendations/{recommendationId}/snooze', [ReportController::class, 'snoozeRecommendation']);

    $trafficRules = static fn (): TrafficRulesController => new TrafficRulesController(new TrafficRulesService());
    $trafficResponse = static fn (array $payload, int $defaultStatus = 200) => response()->json($payload, (int) ($payload['status'] ?? $defaultStatus));

    Route::post('/domains/{domainId}/redirects', static fn (string $domainId) => $trafficResponse($trafficRules()->createRedirect($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/redirects', static fn (string $domainId) => response()->json($trafficRules()->listRedirects($domainId)));
    Route::patch('/domains/{domainId}/redirects/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updateRedirect($domainId, $ruleId, request()->all())));
    Route::delete('/domains/{domainId}/redirects/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deleteRedirect($domainId, $ruleId)));
    Route::post('/domains/{domainId}/redirects/import', static fn (string $domainId) => $trafficResponse($trafficRules()->importRedirects($domainId, request()->all())));
    Route::get('/domains/{domainId}/redirects/export', static fn (string $domainId) => response()->json($trafficRules()->exportRedirects($domainId)));
    Route::post('/domains/{domainId}/redirects/test', static fn (string $domainId) => $trafficResponse($trafficRules()->testRedirect($domainId, request()->all())));

    Route::post('/domains/{domainId}/rate-limits', static fn (string $domainId) => $trafficResponse($trafficRules()->createRateLimit($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/rate-limits', static fn (string $domainId) => response()->json($trafficRules()->listRateLimits($domainId)));
    Route::post('/domains/{domainId}/rate-limits/dry-run', static fn (string $domainId) => $trafficResponse($trafficRules()->dryRunRateLimit($domainId, request()->all())));
    Route::patch('/domains/{domainId}/rate-limits/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updateRateLimit($domainId, $ruleId, request()->all())));
    Route::post('/domains/{domainId}/rate-limits/{ruleId}/detach-managed', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->detachManagedRule($domainId, 'rate_limit', $ruleId)));
    Route::delete('/domains/{domainId}/rate-limits/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deleteRateLimit($domainId, $ruleId)));

    Route::get('/domains/{domainId}/waiting-room', static fn (string $domainId) => response()->json($trafficRules()->getWaitingRoom($domainId)));
    Route::patch('/domains/{domainId}/waiting-room', static fn (string $domainId) => $trafficResponse($trafficRules()->updateWaitingRoom($domainId, request()->all())));
    Route::post('/domains/{domainId}/waiting-room/emergency/activate', static fn (string $domainId) => $trafficResponse($trafficRules()->activateWaitingRoomEmergency($domainId, request()->all())));
    Route::post('/domains/{domainId}/waiting-room/emergency/deactivate', static fn (string $domainId) => $trafficResponse($trafficRules()->deactivateWaitingRoomEmergency($domainId)));

    Route::post('/domains/{domainId}/waf-rules', static fn (string $domainId) => $trafficResponse($trafficRules()->createWaf($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/waf-rules', static fn (string $domainId) => response()->json($trafficRules()->listWaf($domainId)));
    Route::patch('/domains/{domainId}/waf-rules/{wafId}', static fn (string $domainId, string $wafId) => $trafficResponse($trafficRules()->updateWaf($domainId, $wafId, request()->all())));
    Route::post('/domains/{domainId}/waf-rules/{ruleId}/detach-managed', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->detachManagedRule($domainId, 'waf_rule', $ruleId)));
    Route::delete('/domains/{domainId}/waf-rules/{wafId}', static fn (string $domainId, string $wafId) => $trafficResponse($trafficRules()->deleteWaf($domainId, $wafId)));

    Route::post('/domains/{domainId}/headers', static fn (string $domainId) => $trafficResponse($trafficRules()->createHeaderRule($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/headers', static fn (string $domainId) => response()->json($trafficRules()->listHeaderRules($domainId)));
    Route::patch('/domains/{domainId}/headers/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updateHeaderRule($domainId, $ruleId, request()->all())));
    Route::delete('/domains/{domainId}/headers/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deleteHeaderRule($domainId, $ruleId)));

    Route::post('/domains/{domainId}/ip-rules', static fn (string $domainId) => $trafficResponse($trafficRules()->createIpRule($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/ip-rules', static fn (string $domainId) => response()->json($trafficRules()->listIpRules($domainId)));
    Route::patch('/domains/{domainId}/ip-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updateIpRule($domainId, $ruleId, request()->all())));
    Route::delete('/domains/{domainId}/ip-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deleteIpRule($domainId, $ruleId)));

    Route::post('/domains/{domainId}/cache-rules', static fn (string $domainId) => $trafficResponse($trafficRules()->createCacheRule($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/cache-rules', static fn (string $domainId) => response()->json($trafficRules()->listCacheRules($domainId)));
    Route::patch('/domains/{domainId}/cache-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updateCacheRule($domainId, $ruleId, request()->all())));
    Route::delete('/domains/{domainId}/cache-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deleteCacheRule($domainId, $ruleId)));
    Route::get('/domains/{domainId}/cache/settings', static fn (string $domainId) => response()->json($trafficRules()->getDomainCacheSettings($domainId)));
    Route::put('/domains/{domainId}/cache/settings', static fn (string $domainId) => $trafficResponse($trafficRules()->setDomainCacheSettings($domainId, request()->all())));
    Route::post('/domains/{domainId}/cache/purge', static fn (string $domainId) => $trafficResponse($trafficRules()->createCachePurgeRequest($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/cache/purge-requests', static fn (string $domainId) => response()->json($trafficRules()->listCachePurgeRequests($domainId)));
    Route::get('/domains/{domainId}/cache/purge-requests/{requestId}', static fn (string $domainId, string $requestId) => $trafficResponse($trafficRules()->getCachePurgeRequest($domainId, $requestId)));

    Route::post('/domains/{domainId}/page-rules', static fn (string $domainId) => $trafficResponse($trafficRules()->createPageRule($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/page-rules', static fn (string $domainId) => response()->json($trafficRules()->listPageRules($domainId)));
    Route::patch('/domains/{domainId}/page-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updatePageRule($domainId, $ruleId, request()->all())));
    Route::delete('/domains/{domainId}/page-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deletePageRule($domainId, $ruleId)));
    Route::post('/domains/{domainId}/page-rules/test', static fn (string $domainId) => $trafficResponse($trafficRules()->testPageRule($domainId, request()->all())));

    Route::get('/dns/operations', [DnsOperationsController::class, 'status']);
    Route::get('/dns/zones', [DnsOperationsController::class, 'zones']);
    Route::get('/dns/zones/{zone}/actual', [DnsOperationsController::class, 'actual']);
    Route::get('/dns/desired', [DnsOperationsController::class, 'desired']);
    Route::post('/dns/dry-run', [DnsOperationsController::class, 'dryRun']);
    Route::post('/dns/force-sync', [DnsOperationsController::class, 'forceSync']);

    Route::get('/edge/nodes', [EdgeController::class, 'index']);
    Route::get('/edge/config/status', [EdgeController::class, 'configStatus']);
    Route::post('/edge/config/publish', [EdgeController::class, 'publishConfig']);
    Route::get('/edges/dns', [EdgeController::class, 'dns']);
});

Route::middleware('edge.auth')->prefix('/v1')->group(function (): void {
    Route::post('/edge/register', [EdgeController::class, 'register']);
    Route::post('/edge/heartbeat', [EdgeController::class, 'heartbeat']);
    Route::get('/edge/config', [EdgeController::class, 'config']);
    Route::post('/collector/usage', [CollectorController::class, 'usage']);
    Route::post('/collector/security-events', [CollectorController::class, 'securityEvents']);
});
