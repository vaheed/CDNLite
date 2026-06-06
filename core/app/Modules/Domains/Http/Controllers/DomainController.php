<?php

namespace App\Modules\Domains\Http\Controllers;

use App\Modules\Domains\Services\DomainService;
use App\Support\Validator;

class DomainController
{
    public function __construct(private DomainService $service)
    {
    }

    public function index(): array
    {
        return ['data' => $this->service->all()];
    }

    public function show(string $domainId): ?array
    {
        $domain = $this->service->find($domainId);
        return $domain ? ['data' => $domain] : null;
    }

    public function store(array $input): array
    {
        if (array_key_exists('origin_port', $input)) {
            return ['error' => 'origin_port_not_supported', 'field' => 'origin_port', 'status' => 422];
        }
        if (isset($input['zone_name']) && !isset($input['domain'])) {
            $input['domain'] = $input['zone_name'];
        }
        $domain = Validator::domain($input, 'domain');
        if (($domain['ok'] ?? false) !== true) {
            return $domain;
        }
        if (array_key_exists('origin_shield_header_name', $input)) {
            $header = Validator::optionalString($input, 'origin_shield_header_name', 255);
            if (($header['ok'] ?? false) !== true) {
                return $header;
            }
        }
        if (array_key_exists('origin_shield_secret', $input)) {
            $secret = Validator::requiredString($input, 'origin_shield_secret', 4096);
            if (($secret['ok'] ?? false) !== true) {
                return $secret;
            }
            $input['origin_shield_header_value_hash'] = hash('sha256', (string) $secret['value']);
            unset($input['origin_shield_secret']);
        }

        if ($this->service->findByDomain((string) $domain['value']) !== null) {
            return ['error' => 'domain_already_exists', 'status' => 422];
        }

        $input['name'] = trim((string) ($input['display_name'] ?? $input['name'] ?? $domain['value']));
        $input['domain'] = $domain['value'];
        unset($input['origin_host'], $input['origin_scheme'], $input['geo_origins'], $input['proxy_enabled']);

        try {
            return ['data' => $this->service->create($input)];
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage(), 'status' => 502];
        }
    }

    public function verifyNameservers(string $domainId): ?array
    {
        $domain = (new DomainVerificationService())->verify($domainId);
        return $domain ? ['data' => $domain] : null;
    }

    public function activate(string $domainId, array $input): ?array
    {
        try {
            $domain = $this->service->activate($domainId, (bool) ($input['override'] ?? false));
            return $domain ? ['data' => $domain] : null;
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage(), 'status' => 422];
        }
    }

    public function update(string $domainId, array $input): ?array
    {
        if (array_key_exists('origin_port', $input)) {
            return ['error' => 'origin_port_not_supported', 'field' => 'origin_port', 'status' => 422];
        }
        unset($input['origin_host'], $input['origin_scheme'], $input['geo_origins'], $input['proxy_enabled']);
        if (array_key_exists('domain', $input)) {
            $domain = Validator::domain($input, 'domain');
            if (($domain['ok'] ?? false) !== true) {
                return $domain;
            }
            $input['domain'] = $domain['value'];
        }
        if (array_key_exists('origin_shield_header_name', $input)) {
            $header = Validator::optionalString($input, 'origin_shield_header_name', 255);
            if (($header['ok'] ?? false) !== true) {
                return $header;
            }
        }
        if (array_key_exists('origin_shield_secret', $input)) {
            $secret = Validator::requiredString($input, 'origin_shield_secret', 4096);
            if (($secret['ok'] ?? false) !== true) {
                return $secret;
            }
            $input['origin_shield_header_value_hash'] = hash('sha256', (string) $secret['value']);
            unset($input['origin_shield_secret']);
        }

        if (isset($input['domain'])) {
            $existing = $this->service->findByDomain((string) $input['domain']);
            if ($existing !== null && (string) $existing['id'] !== $domainId) {
                return ['error' => 'domain_already_exists', 'status' => 422];
            }
        }

        $domain = $this->service->update($domainId, $input);
        return $domain ? ['data' => $domain] : null;
    }

    public function delete(string $domainId): array
    {
        return $this->service->delete($domainId)
            ? ['ok' => true]
            : ['error' => 'domain_not_found', 'status' => 404];
    }

}
