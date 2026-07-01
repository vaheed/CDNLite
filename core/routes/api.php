<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\CollectorController;
use App\Http\Controllers\Api\DnsOperationsController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\EdgeController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Api\OnboardingController;
use App\Services\ControlPlane\OnboardingService;
use App\Http\Controllers\Api\TrafficRulesController;
use App\Services\ControlPlane\TrafficRulesService;
use App\Http\Controllers\Api\SettingsController as PlatformSettingsController;
use App\Services\ControlPlane\EdgeConfigSnapshotService;
use App\Services\ControlPlane\SslCertificateService;
use App\Services\ControlPlane\SslRenewalService;
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
    Route::get('/events', [OperationsController::class, 'events']);
    Route::get('/jobs', [OperationsController::class, 'jobs']);
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
    Route::get('/domains/{domainId}/dns/records/{recordId}/geo-routes', [DomainController::class, 'dnsRecordGeoRoutes']);
    Route::put('/domains/{domainId}/dns/records/{recordId}/geo-routes', [DomainController::class, 'updateDnsRecordGeoRoutes']);
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
    Route::post('/domains/{domainId}/route-debug', static function (string $domainId, Request $request, EdgeConfigSnapshotService $config) {
        return response()->json(['data' => $config->debugRoute($domainId, $request->all())]);
    });
    Route::get('/domains/{domainId}/recommendations', [ReportController::class, 'listRecommendations']);
    Route::post('/domains/{domainId}/recommendations/generate', [ReportController::class, 'generateRecommendations']);
    Route::post('/domains/{domainId}/recommendations/{recommendationId}/apply', [ReportController::class, 'applyRecommendation']);
    Route::post('/domains/{domainId}/recommendations/{recommendationId}/dismiss', [ReportController::class, 'dismissRecommendation']);
    Route::post('/domains/{domainId}/recommendations/{recommendationId}/snooze', [ReportController::class, 'snoozeRecommendation']);

    $trafficRules = static fn (): TrafficRulesController => new TrafficRulesController(new TrafficRulesService());
    $onboarding = static fn (): OnboardingController => new OnboardingController(new OnboardingService());
    $trafficJson = static fn (array $payload, int $status = 200) => response()->json($payload, $status, [], JSON_UNESCAPED_SLASHES);
    $trafficResponse = static fn (array $payload, int $defaultStatus = 200) => $trafficJson($payload, (int) ($payload['status'] ?? $defaultStatus));

    Route::post('/domains/{domainId}/redirects', static fn (string $domainId) => $trafficResponse($trafficRules()->createRedirect($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/redirects', static fn (string $domainId) => $trafficJson($trafficRules()->listRedirects($domainId)));
    Route::patch('/domains/{domainId}/redirects/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updateRedirect($domainId, $ruleId, request()->all())));
    Route::delete('/domains/{domainId}/redirects/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deleteRedirect($domainId, $ruleId)));
    Route::post('/domains/{domainId}/redirects/import', static fn (string $domainId) => $trafficResponse($trafficRules()->importRedirects($domainId, request()->all())));
    Route::get('/domains/{domainId}/redirects/export', static fn (string $domainId) => $trafficJson($trafficRules()->exportRedirects($domainId)));
    Route::post('/domains/{domainId}/redirects/test', static fn (string $domainId) => $trafficResponse($trafficRules()->testRedirect($domainId, request()->all())));

    Route::post('/domains/{domainId}/rate-limits', static fn (string $domainId) => $trafficResponse($trafficRules()->createRateLimit($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/rate-limits', static fn (string $domainId) => $trafficJson($trafficRules()->listRateLimits($domainId)));
    Route::post('/domains/{domainId}/rate-limits/dry-run', static fn (string $domainId) => $trafficResponse($trafficRules()->dryRunRateLimit($domainId, request()->all())));
    Route::patch('/domains/{domainId}/rate-limits/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updateRateLimit($domainId, $ruleId, request()->all())));
    Route::post('/domains/{domainId}/rate-limits/{ruleId}/detach-managed', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->detachManagedRule($domainId, 'rate_limit', $ruleId)));
    Route::delete('/domains/{domainId}/rate-limits/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deleteRateLimit($domainId, $ruleId)));

    Route::get('/domains/{domainId}/waiting-room', static fn (string $domainId) => $trafficJson($trafficRules()->getWaitingRoom($domainId)));
    Route::patch('/domains/{domainId}/waiting-room', static fn (string $domainId) => $trafficResponse($trafficRules()->updateWaitingRoom($domainId, request()->all())));
    Route::post('/domains/{domainId}/waiting-room/emergency/activate', static fn (string $domainId) => $trafficResponse($trafficRules()->activateWaitingRoomEmergency($domainId, request()->all())));
    Route::post('/domains/{domainId}/waiting-room/emergency/deactivate', static fn (string $domainId) => $trafficResponse($trafficRules()->deactivateWaitingRoomEmergency($domainId)));

    Route::post('/domains/{domainId}/waf-rules', static fn (string $domainId) => $trafficResponse($trafficRules()->createWaf($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/waf-rules', static fn (string $domainId) => $trafficJson($trafficRules()->listWaf($domainId)));
    Route::patch('/domains/{domainId}/waf-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updateWaf($domainId, $ruleId, request()->all())));
    Route::post('/domains/{domainId}/waf-rules/{ruleId}/detach-managed', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->detachManagedRule($domainId, 'waf_rule', $ruleId)));
    Route::delete('/domains/{domainId}/waf-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deleteWaf($domainId, $ruleId)));

    Route::post('/domains/{domainId}/headers', static fn (string $domainId) => $trafficResponse($trafficRules()->createHeaderRule($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/headers', static fn (string $domainId) => $trafficJson($trafficRules()->listHeaderRules($domainId)));
    Route::patch('/domains/{domainId}/headers/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updateHeaderRule($domainId, $ruleId, request()->all())));
    Route::delete('/domains/{domainId}/headers/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deleteHeaderRule($domainId, $ruleId)));

    Route::post('/domains/{domainId}/ip-rules', static fn (string $domainId) => $trafficResponse($trafficRules()->createIpRule($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/ip-rules', static fn (string $domainId) => $trafficJson($trafficRules()->listIpRules($domainId)));
    Route::patch('/domains/{domainId}/ip-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updateIpRule($domainId, $ruleId, request()->all())));
    Route::delete('/domains/{domainId}/ip-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deleteIpRule($domainId, $ruleId)));

    Route::post('/domains/{domainId}/cache-rules', static fn (string $domainId) => $trafficResponse($trafficRules()->createCacheRule($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/cache-rules', static fn (string $domainId) => $trafficJson($trafficRules()->listCacheRules($domainId)));
    Route::patch('/domains/{domainId}/cache-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updateCacheRule($domainId, $ruleId, request()->all())));
    Route::delete('/domains/{domainId}/cache-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deleteCacheRule($domainId, $ruleId)));
    Route::get('/domains/{domainId}/cache/settings', static fn (string $domainId) => $trafficJson($trafficRules()->getDomainCacheSettings($domainId)));
    Route::put('/domains/{domainId}/cache/settings', static fn (string $domainId) => $trafficResponse($trafficRules()->setDomainCacheSettings($domainId, request()->all())));
    Route::post('/domains/{domainId}/cache/purge', static fn (string $domainId) => $trafficResponse($trafficRules()->createCachePurgeRequest($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/cache/purge-requests', static fn (string $domainId) => $trafficJson($trafficRules()->listCachePurgeRequests($domainId)));
    Route::get('/domains/{domainId}/cache/purge-requests/{requestId}', static fn (string $domainId, string $requestId) => $trafficResponse($trafficRules()->getCachePurgeRequest($domainId, $requestId)));

    Route::post('/domains/{domainId}/page-rules', static fn (string $domainId) => $trafficResponse($trafficRules()->createPageRule($domainId, request()->all()), 201));
    Route::get('/domains/{domainId}/page-rules', static fn (string $domainId) => $trafficJson($trafficRules()->listPageRules($domainId)));
    Route::patch('/domains/{domainId}/page-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->updatePageRule($domainId, $ruleId, request()->all())));
    Route::delete('/domains/{domainId}/page-rules/{ruleId}', static fn (string $domainId, string $ruleId) => $trafficResponse($trafficRules()->deletePageRule($domainId, $ruleId)));
    Route::post('/domains/{domainId}/page-rules/test', static fn (string $domainId) => $trafficResponse($trafficRules()->testPageRule($domainId, request()->all())));

    Route::get('/domains/{domainId}/ssl', static function (string $domainId, SslCertificateService $ssl) {
        try {
            return response()->json(['data' => $ssl->settings($domainId)]);
        } catch (\OutOfBoundsException) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }
    });
    Route::patch('/domains/{domainId}/ssl/settings', static function (Request $request, string $domainId, SslCertificateService $ssl) {
        try {
            return response()->json(['data' => $ssl->updateSettings($domainId, $request->all(), (string) (($request->attributes->get('admin_user')['id'] ?? null) ?: 'system'))]);
        } catch (\InvalidArgumentException $error) {
            return response()->json(['error' => 'invalid_field', 'field' => 'min_tls_version', 'detail' => $error->getMessage()], 422);
        } catch (\DomainException $error) {
            return response()->json(['error' => $error->getMessage()], 422);
        } catch (\OutOfBoundsException) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }
    });
    Route::get('/domains/{domainId}/ssl/certificates', static fn (string $domainId, SslCertificateService $ssl) => response()->json(['data' => $ssl->listCertificates($domainId)]));
    Route::post('/domains/{domainId}/ssl/request', static function (Request $request, string $domainId, SslCertificateService $ssl) {
        if ($request->exists('hostnames') && !is_array($request->input('hostnames'))) {
            return response()->json(['error' => 'invalid_field', 'field' => 'hostnames', 'detail' => 'must_be_array'], 422);
        }
        try {
            return response()->json(['data' => $ssl->requestJob($domainId, (array) $request->input('hostnames', []), (string) (($request->attributes->get('admin_user')['id'] ?? null) ?: 'system'))], 202);
        } catch (\InvalidArgumentException $error) {
            return response()->json(['error' => 'invalid_field', 'field' => 'hostnames', 'detail' => $error->getMessage()], 422);
        } catch (\DomainException $error) {
            $body = ['error' => $error->getMessage()];
            if ($error->getMessage() === 'domain_must_be_active') {
                $body['detail'] = 'Verify nameservers before requesting managed SSL.';
            }
            return response()->json($body, 422);
        } catch (\OutOfBoundsException) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }
    });
    Route::get('/domains/{domainId}/ssl/jobs/{jobId}', static function (string $domainId, string $jobId, SslCertificateService $ssl) {
        $job = $ssl->getJob($domainId, $jobId);
        return $job === null ? response()->json(['error' => 'ssl_job_not_found'], 404) : response()->json(['data' => $job]);
    });
    Route::post('/domains/{domainId}/ssl/renew', static function (string $domainId, SslRenewalService $renewal) {
        try {
            return response()->json(['data' => $renewal->forceRenew($domainId)]);
        } catch (\DomainException $error) {
            return response()->json(['error' => $error->getMessage()], 422);
        } catch (\OutOfBoundsException $error) {
            return response()->json(['error' => $error->getMessage() ?: 'domain_not_found'], 404);
        }
    });
    Route::get('/domains/{domainId}/ssl/acme-status', static fn (string $domainId, SslCertificateService $ssl) => response()->json(['data' => $ssl->status($domainId)]));
    Route::post('/domains/{domainId}/ssl/check', static function (Request $request, string $domainId, SslCertificateService $ssl) {
        if ($request->exists('hostnames') && !is_array($request->input('hostnames'))) {
            return response()->json(['error' => 'invalid_field', 'field' => 'hostnames', 'detail' => 'must_be_array'], 422);
        }
        try {
            return response()->json(['data' => $ssl->checkCertificates($domainId, (array) $request->input('hostnames', []))]);
        } catch (\InvalidArgumentException $error) {
            return response()->json(['error' => 'invalid_field', 'field' => 'hostnames', 'detail' => $error->getMessage()], 422);
        } catch (\OutOfBoundsException) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }
    });
    Route::post('/domains/{domainId}/ssl/manual-certificate', static function (Request $request, string $domainId, SslCertificateService $ssl) {
        $validated = $request->validate([
            'hostname' => ['required', 'string', 'max:255'],
            'certificate_pem' => ['required', 'string', 'max:65535'],
            'private_key_pem' => ['required', 'string', 'max:65535'],
        ]);
        try {
            return response()->json(['data' => $ssl->importManualCertificate($domainId, $validated['hostname'], $validated['certificate_pem'], $validated['private_key_pem'], (string) (($request->attributes->get('admin_user')['id'] ?? null) ?: 'system'))]);
        } catch (\InvalidArgumentException $error) {
            return response()->json(['error' => 'invalid_field', 'field' => 'certificate', 'detail' => $error->getMessage()], 422);
        } catch (\RuntimeException $error) {
            return response()->json(['error' => 'invalid_field', 'field' => 'CDNLITE_SSL_SECRET_KEY', 'detail' => $error->getMessage()], 422);
        } catch (\OutOfBoundsException) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }
    });

    Route::get('/domains/{domainId}/protection/profiles', static fn (string $domainId) => $trafficJson($trafficRules()->listProtectionProfiles($domainId)));
    Route::get('/domains/{domainId}/protection/waf-presets', static fn (string $domainId) => $trafficJson($trafficRules()->listManagedWafPresets($domainId)));
    Route::get('/domains/{domainId}/protection/rate-limit-templates', static fn (string $domainId) => $trafficJson($trafficRules()->listSmartRateLimitTemplates($domainId)));
    Route::get('/domains/{domainId}/protection/api-paths', static fn (string $domainId) => $trafficJson($trafficRules()->discoverApiPaths($domainId)));
    Route::post('/domains/{domainId}/protection/profiles/{profileKey}/preview', static fn (string $domainId, string $profileKey) => $trafficResponse($trafficRules()->previewProtectionProfile($domainId, $profileKey, request()->all())));
    Route::post('/domains/{domainId}/protection/profiles/{profileKey}/apply', static fn (string $domainId, string $profileKey) => $trafficResponse($trafficRules()->applyProtectionProfile($domainId, $profileKey, request()->all())));
    Route::post('/domains/{domainId}/protection/profiles/{profileId}/disable', static fn (string $domainId, string $profileId) => $trafficResponse($trafficRules()->disableProtectionProfile($domainId, $profileId, request()->all())));
    Route::get('/domains/{domainId}/protection/intents', static fn (string $domainId) => $trafficJson($trafficRules()->listProtectionIntents($domainId)));
    Route::post('/domains/{domainId}/protection/intents/{intentKey}/preview', static fn (string $domainId, string $intentKey) => $trafficResponse($trafficRules()->previewProtectionIntent($domainId, $intentKey, request()->all())));
    Route::post('/domains/{domainId}/protection/intents/{intentKey}/enable', static fn (string $domainId, string $intentKey) => $trafficResponse($trafficRules()->enableProtectionIntent($domainId, $intentKey, request()->all())));
    Route::post('/domains/{domainId}/protection/intents/{intentId}/disable', static fn (string $domainId, string $intentId) => $trafficResponse($trafficRules()->disableProtectionIntent($domainId, $intentId, request()->all())));
    Route::post('/domains/{domainId}/protection/intents/{intentId}/undo', static fn (string $domainId, string $intentId) => $trafficResponse($trafficRules()->undoProtectionIntent($domainId, $intentId)));

    Route::get('/domains/{domainId}/onboarding', static fn (string $domainId) => $trafficResponse($onboarding()->show($domainId)));
    Route::post('/domains/{domainId}/onboarding/answers', static fn (string $domainId) => $trafficResponse($onboarding()->answers($domainId, request()->all())));
    Route::post('/domains/{domainId}/onboarding/preview', static fn (string $domainId) => $trafficResponse($onboarding()->preview($domainId)));
    Route::post('/domains/{domainId}/onboarding/apply', static fn (string $domainId) => $trafficResponse($onboarding()->apply($domainId, request()->all())));
    Route::post('/domains/{domainId}/onboarding/skip', static fn (string $domainId) => $trafficResponse($onboarding()->skip($domainId)));
    Route::post('/domains/{domainId}/onboarding/resume', static fn (string $domainId) => $trafficResponse($onboarding()->resume($domainId)));

    Route::get('/dns/operations', [DnsOperationsController::class, 'status']);
    Route::get('/dns/zones', [DnsOperationsController::class, 'zones']);
    Route::get('/dns/zones/{zone}/actual', [DnsOperationsController::class, 'actual']);
    Route::get('/dns/desired', [DnsOperationsController::class, 'desired']);
    Route::post('/dns/dry-run', [DnsOperationsController::class, 'dryRun']);
    Route::post('/dns/force-sync', [DnsOperationsController::class, 'forceSync']);

    Route::get('/edge/nodes', [EdgeController::class, 'index']);
    Route::get('/edges/pools', [EdgeController::class, 'pools']);
    Route::get('/edge/config/status', [EdgeController::class, 'configStatus']);
    Route::post('/edge/config/publish', [EdgeController::class, 'publishConfig']);
    Route::get('/edges/dns', [EdgeController::class, 'dns']);

    Route::get('/config/snapshots', static function (Request $request, EdgeConfigSnapshotService $config) {
        return response()->json(['data' => $config->snapshots((int) $request->query('limit', 20), (int) $request->query('offset', 0))]);
    });
    Route::get('/config/snapshots/latest', static fn (EdgeConfigSnapshotService $config) => response()->json(['data' => $config->latestSnapshotSummary()]));
    Route::get('/config/snapshots/{version}', static function (int $version, EdgeConfigSnapshotService $config) {
        try {
            $snapshot = $config->snapshot($version);
        } catch (\DomainException $error) {
            return response()->json(['error' => $error->getMessage()], 403);
        }

        return $snapshot === null
            ? response()->json(['error' => 'config_snapshot_not_found'], 404)
            : response()->json(['data' => $snapshot]);
    })->whereNumber('version');
    Route::post('/config/snapshots/diff', static function (Request $request, EdgeConfigSnapshotService $config) {
        $validated = $request->validate([
            'from_version' => ['required', 'integer', 'min:1'],
            'to_version' => ['required', 'integer', 'min:1'],
        ]);
        try {
            return response()->json(['data' => $config->diff((int) $validated['from_version'], (int) $validated['to_version'])]);
        } catch (\DomainException $error) {
            return response()->json(['error' => $error->getMessage()], 403);
        } catch (\OutOfBoundsException) {
            return response()->json(['error' => 'config_snapshot_not_found'], 404);
        }
    });
    Route::post('/config/snapshots/{version}/rollback', static function (Request $request, int $version, EdgeConfigSnapshotService $config) {
        try {
            return response()->json(['data' => $config->rollback($version, (string) (($request->attributes->get('admin_user')['id'] ?? null) ?: 'system'))]);
        } catch (\DomainException $error) {
            return response()->json(['error' => $error->getMessage()], 403);
        } catch (\OutOfBoundsException) {
            return response()->json(['error' => 'config_snapshot_not_found'], 404);
        }
    })->whereNumber('version');
    Route::post('/config/snapshots/rebuild', static fn (EdgeConfigSnapshotService $config) => response()->json(['data' => $config->publish()]));
});

Route::middleware('edge.auth')->prefix('/v1')->group(function (): void {
    Route::post('/edge/register', [EdgeController::class, 'register']);
    Route::post('/edge/heartbeat', [EdgeController::class, 'heartbeat']);
    Route::get('/edge/config', [EdgeController::class, 'config']);
    Route::post('/collector/usage', [CollectorController::class, 'usage']);
    Route::post('/collector/security-events', [CollectorController::class, 'securityEvents']);
});
