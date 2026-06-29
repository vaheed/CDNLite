<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDnsRecordRequest;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Requests\StoreOriginRequest;
use App\Http\Resources\DomainResource;
use App\Services\ControlPlane\DnsRecordService;
use App\Services\ControlPlane\DomainLifecycleService;
use App\Services\ControlPlane\DomainNameserverVerifier;
use App\Services\ControlPlane\OriginLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class DomainController extends Controller
{
    public function index(DomainLifecycleService $domains): JsonResponse
    {
        return response()->json(['data' => DomainResource::collection($domains->list())]);
    }

    public function store(StoreDomainRequest $request, DomainLifecycleService $domains): JsonResponse
    {
        $validated = $request->validated();
        if ($domains->findByDomain($validated['domain']) !== null) {
            return response()->json(['error' => 'domain_already_exists'], 422);
        }

        $created = $domains->create($validated, $this->adminUser($request));

        return response()->json(['data' => new DomainResource($created)], 201);
    }

    public function show(string $domainId, DomainLifecycleService $domains): JsonResponse
    {
        $domain = $domains->find($domainId);

        return $domain === null
            ? response()->json(['error' => 'domain_not_found'], 404)
            : response()->json(['data' => new DomainResource($domain)]);
    }

    public function update(Request $request, string $domainId, DomainLifecycleService $domains): JsonResponse
    {
        if ($domains->find($domainId) === null) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'domain' => ['sometimes', 'string', 'max:253', 'regex:/^(?!-)(?:[a-z0-9-]{1,63}\.)+[a-z]{2,63}$/i'],
            'status' => ['sometimes', 'in:pending_nameserver,active,disabled'],
            'origin_shield_header_name' => ['nullable', 'string', 'max:255'],
            'origin_shield_secret' => ['sometimes', 'string', 'max:4096'],
        ]);
        if (isset($validated['domain'])) {
            $existingForDomain = $domains->findByDomain($validated['domain']);
            if ($existingForDomain !== null && (string) $existingForDomain['id'] !== $domainId) {
                return response()->json(['error' => 'domain_already_exists'], 422);
            }
        }

        $updated = $domains->update($domainId, $validated, $this->adminUser($request));

        return response()->json(['data' => new DomainResource($updated)]);
    }

    public function destroy(Request $request, string $domainId, DomainLifecycleService $domains): JsonResponse
    {
        if (!$domains->delete($domainId, $this->adminUser($request))) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        return response()->json(['ok' => true]);
    }

    public function verifyNameservers(string $domainId, DomainNameserverVerifier $verifier): JsonResponse
    {
        $result = $verifier->verify($domainId);

        return $result === null
            ? response()->json(['error' => 'domain_not_found'], 404)
            : response()->json($this->verificationResponse($result));
    }

    public function forceVerifyNameservers(Request $request, string $domainId, DomainNameserverVerifier $verifier): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $result = $verifier->forceVerify($domainId, $validated['reason'], $this->adminUser($request));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return $result === null
            ? response()->json(['error' => 'domain_not_found'], 404)
            : response()->json($this->verificationResponse($result));
    }

    public function reseedExpectedNameservers(Request $request, string $domainId, DomainNameserverVerifier $verifier): JsonResponse
    {
        try {
            $result = $verifier->reseedExpected($domainId, $this->adminUser($request));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return $result === null
            ? response()->json(['error' => 'domain_not_found'], 404)
            : response()->json($this->verificationResponse($result));
    }

    public function activate(Request $request, string $domainId, DomainLifecycleService $domains): JsonResponse
    {
        $validated = $request->validate(['override' => ['sometimes', 'boolean']]);
        try {
            $domain = $domains->activate($domainId, (bool) ($validated['override'] ?? false), $this->adminUser($request));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return $domain === null
            ? response()->json(['error' => 'domain_not_found'], 404)
            : response()->json(['data' => new DomainResource($domain)]);
    }

    public function dnsStatus(string $domainId, DomainLifecycleService $domains): JsonResponse
    {
        $domain = $domains->find($domainId);
        if ($domain === null) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        $zoneName = rtrim((string) $domain['domain'], '.') . '.';
        $state = DB::table('dns_sync_state')
            ->whereIn('zone_name', [(string) $domain['domain'], $zoneName])
            ->orderByRaw('CASE WHEN zone_name = ? THEN 0 ELSE 1 END', [$zoneName])
            ->first();
        $lastError = $state?->last_error ?? null;
        $pendingChanges = (int) ($state?->pending_changes ?? 0);

        return response()->json([
            'data' => [
                'domain_id' => $domainId,
                'zone_name' => $state?->zone_name ?? $zoneName,
                'status' => $state?->status ?? 'pending',
                'converged' => $state !== null && $pendingChanges === 0 && $lastError === null,
                'pending_changes' => $pendingChanges,
                'last_success_at' => $state?->last_success_at === null ? null : (int) $state->last_success_at,
                'last_attempt_at' => $state?->last_attempt_at === null ? null : (int) $state->last_attempt_at,
                'last_error' => $lastError,
            ],
        ]);
    }

    public function dnsRecords(string $domainId, DnsRecordService $dnsRecords): JsonResponse
    {
        $records = $dnsRecords->list($domainId);

        return $records === null
            ? response()->json(['error' => 'domain_not_found'], 404)
            : response()->json(['data' => $records]);
    }

    public function storeDnsRecord(StoreDnsRecordRequest $request, string $domainId, DnsRecordService $dnsRecords): JsonResponse
    {
        try {
            $record = $dnsRecords->create($domainId, $request->validated(), $this->adminUser($request));
        } catch (RuntimeException $e) {
            return $this->dnsError($e);
        }

        return $record === null
            ? response()->json(['error' => 'domain_not_found'], 404)
            : response()->json(['data' => $record], 201);
    }

    public function showDnsRecord(string $domainId, string $recordId, DnsRecordService $dnsRecords): JsonResponse
    {
        $record = $dnsRecords->find($domainId, $recordId);

        return $record === null
            ? response()->json(['error' => 'dns_record_not_found'], 404)
            : response()->json(['data' => $record]);
    }

    public function updateDnsRecord(Request $request, string $domainId, string $recordId, DnsRecordService $dnsRecords): JsonResponse
    {
        if ($request->json()->all() === []) {
            return response()->json(['error' => 'invalid_request', 'detail' => 'at_least_one_field_required'], 422);
        }

        $validated = validator($request->json()->all(), $this->dnsRecordUpdateRules())->validate();

        try {
            $record = $dnsRecords->update($domainId, $recordId, $validated, $this->adminUser($request));
        } catch (RuntimeException $e) {
            return $this->dnsError($e);
        }

        return $record === null
            ? response()->json(['error' => 'dns_record_not_found'], 404)
            : response()->json(['data' => $record]);
    }

    public function destroyDnsRecord(Request $request, string $domainId, string $recordId, DnsRecordService $dnsRecords): JsonResponse
    {
        return $dnsRecords->delete($domainId, $recordId, $this->adminUser($request))
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'dns_record_not_found'], 404);
    }

    public function reconcileDnsRecord(Request $request, string $domainId, string $recordId, DnsRecordService $dnsRecords): JsonResponse
    {
        $record = $dnsRecords->queueReconcile($domainId, $recordId, $this->adminUser($request));

        return $record === null
            ? response()->json(['error' => 'dns_record_not_found'], 404)
            : response()->json(['data' => ['record' => $record, 'reconciled' => false, 'queued' => true]]);
    }

    public function origins(string $domainId, OriginLifecycleService $origins): JsonResponse
    {
        $rows = $origins->list($domainId);

        return $rows === null
            ? response()->json(['error' => 'domain_not_found'], 404)
            : response()->json(['data' => $rows]);
    }

    public function storeOrigin(StoreOriginRequest $request, string $domainId, OriginLifecycleService $origins): JsonResponse
    {
        $origin = $origins->create($domainId, $request->validated(), $this->adminUser($request));
        if ($origin === null) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        return response()->json(['data' => $origin], 201);
    }

    public function updateOrigin(Request $request, string $domainId, string $originId, OriginLifecycleService $origins): JsonResponse
    {
        if ($request->json()->all() === []) {
            return response()->json(['error' => 'invalid_request', 'detail' => 'at_least_one_field_required'], 422);
        }

        $validated = validator($request->json()->all(), $this->originUpdateRules())->validate();
        $origin = $origins->update($domainId, $originId, $validated, $this->adminUser($request));

        return $origin === null
            ? response()->json(['error' => 'origin_not_found'], 404)
            : response()->json(['data' => $origin]);
    }

    public function checkOrigin(string $domainId, string $originId, OriginLifecycleService $origins): JsonResponse
    {
        $result = $origins->diagnose($domainId, $originId);

        return $result === null
            ? response()->json(['error' => 'origin_not_found'], 404)
            : response()->json(['data' => $result + ['authoritative' => false, 'source' => 'core_diagnostic_only']]);
    }

    public function testOrigin(string $domainId, string $originId, OriginLifecycleService $origins): JsonResponse
    {
        $result = $origins->diagnose($domainId, $originId);

        return $result === null
            ? response()->json(['error' => 'origin_not_found'], 404)
            : response()->json(['data' => $result]);
    }

    public function originHealth(string $domainId, OriginLifecycleService $origins): JsonResponse
    {
        $report = $origins->healthReport($domainId);

        return $report === null
            ? response()->json(['error' => 'domain_not_found'], 404)
            : response()->json($report);
    }

    public function destroyOrigin(Request $request, string $domainId, string $originId, OriginLifecycleService $origins): JsonResponse
    {
        return $origins->delete($domainId, $originId, $this->adminUser($request))
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'origin_not_found'], 404);
    }

    private function verificationResponse(array $result): array
    {
        $domain = (new DomainResource($result['domain']))->resolve();

        return [
            'data' => array_merge($domain, $result['verification']),
            'verification' => $result['verification'],
        ];
    }

    private function adminUser(Request $request): ?array
    {
        $adminUser = $request->attributes->get('admin_user');

        return is_array($adminUser) ? $adminUser : null;
    }

    private function originUpdateRules(): array
    {
        $rules = (new StoreOriginRequest())->rules();
        $rules['host'] = ['sometimes', 'string', 'max:253'];

        return $rules;
    }

    private function dnsRecordUpdateRules(): array
    {
        return [
            'type' => ['sometimes', 'in:A,AAAA,CNAME,TXT,MX,CAA,NS,SRV'],
            'name' => ['sometimes', 'string', 'max:253'],
            'content' => ['sometimes', 'string', 'max:2048'],
            'ttl' => ['sometimes', 'integer', 'between:60,86400'],
            'priority' => ['sometimes', 'nullable', 'integer', 'between:0,65535'],
            'proxied' => ['sometimes', 'boolean'],
            'origin_host' => ['sometimes', 'nullable', 'string', 'max:253'],
            'status' => ['sometimes', 'in:active,disabled'],
        ];
    }

    private function dnsError(RuntimeException $e): JsonResponse
    {
        $message = $e->getMessage();
        $status = in_array($message, ['dns_record_duplicate', 'dns_record_name_conflict'], true) ? 409 : 422;

        return response()->json(['error' => $message], $status);
    }
}
