<?php

use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\CollectorController;
use App\Http\Controllers\Api\DnsOperationsController;
use App\Http\Controllers\Api\DomainController;
use App\Http\Controllers\Api\EdgeController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/readiness', [HealthController::class, 'readiness']);
Route::post('/v1/admin/login', [AdminAuthController::class, 'login']);

Route::middleware('admin.auth')->prefix('/v1')->group(function (): void {
    Route::get('/admin/me', [AdminAuthController::class, 'me']);
    Route::post('/admin/logout', [AdminAuthController::class, 'logout']);

    Route::get('/overview', [OperationsController::class, 'overview']);
    Route::get('/settings', [OperationsController::class, 'settings']);
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
    Route::get('/domains/{domainId}/dns/records', [DomainController::class, 'dnsRecords']);
    Route::post('/domains/{domainId}/dns/records', [DomainController::class, 'storeDnsRecord']);
    Route::get('/domains/{domainId}/origins', [DomainController::class, 'origins']);
    Route::post('/domains/{domainId}/origins', [DomainController::class, 'storeOrigin']);
    Route::patch('/domains/{domainId}/origins/{originId}', [DomainController::class, 'updateOrigin']);
    Route::delete('/domains/{domainId}/origins/{originId}', [DomainController::class, 'destroyOrigin']);

    Route::get('/dns/operations', [DnsOperationsController::class, 'status']);
    Route::get('/dns/zones', [DnsOperationsController::class, 'zones']);
    Route::get('/dns/desired', [DnsOperationsController::class, 'desired']);
    Route::post('/dns/dry-run', [DnsOperationsController::class, 'dryRun']);
    Route::post('/dns/force-sync', [DnsOperationsController::class, 'dryRun']);

    Route::get('/edge/nodes', [EdgeController::class, 'index']);
    Route::get('/edges/dns', [EdgeController::class, 'dns']);
});

Route::middleware('edge.auth')->prefix('/v1')->group(function (): void {
    Route::post('/edge/register', [EdgeController::class, 'register']);
    Route::post('/edge/heartbeat', [EdgeController::class, 'heartbeat']);
    Route::get('/edge/config', [EdgeController::class, 'config']);
    Route::post('/collector/usage', [CollectorController::class, 'usage']);
});
