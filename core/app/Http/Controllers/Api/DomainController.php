<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDnsRecordRequest;
use App\Http\Requests\StoreDomainRequest;
use App\Http\Requests\StoreOriginRequest;
use App\Http\Resources\DomainResource;
use App\Services\ControlPlane\DomainLifecycleService;
use App\Services\ControlPlane\DomainNameserverVerifier;
use App\Services\ControlPlane\OriginLifecycleService;
use App\Services\ControlPlane\UnixTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    public function dnsRecords(string $domainId): JsonResponse
    {
        return response()->json(['data' => DB::table('dns_records')->where('domain_id', $domainId)->orderBy('name')->get()]);
    }

    public function storeDnsRecord(StoreDnsRecordRequest $request, string $domainId): JsonResponse
    {
        if (DB::table('domains')->where('id', $domainId)->doesntExist()) {
            return response()->json(['error' => 'domain_not_found'], 404);
        }

        $record = $request->validated();
        $now = UnixTime::now();
        $record += [
            'ttl' => 300,
            'priority' => null,
            'proxied' => true,
        ];
        $record = array_merge($record, [
            'id' => (string) Str::uuid(),
            'domain_id' => $domainId,
            'origin_type' => null,
            'origin_content' => null,
            'public_type' => null,
            'public_content' => null,
            'origin_host' => null,
            'origin_tls_verify' => 'ignore',
            'origin_scheme' => null,
            'origin_status' => 'pending',
            'geo_origins_json' => null,
            'routing_policy' => 'standard',
            'managed_by' => null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('dns_records')->insert($record);

        return response()->json(['data' => $record], 201);
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
}
