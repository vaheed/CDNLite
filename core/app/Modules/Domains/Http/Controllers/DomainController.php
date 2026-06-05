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
        $name = Validator::requiredString($input, 'name', 120);
        if (($name['ok'] ?? false) !== true) {
            return $name;
        }
        $domain = Validator::domain($input, 'domain');
        if (($domain['ok'] ?? false) !== true) {
            return $domain;
        }
        $originHost = Validator::requiredString($input, 'origin_host', 255);
        if (($originHost['ok'] ?? false) !== true) {
            return $originHost;
        }
        $originPort = Validator::intRange($input, 'origin_port', 1, 65535, 8080);
        if (($originPort['ok'] ?? false) !== true) {
            return $originPort;
        }
        $originScheme = Validator::enum($input, 'origin_scheme', ['http', 'https']);
        if (($originScheme['ok'] ?? false) !== true) {
            return $originScheme;
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

        $input['name'] = $name['value'];
        $input['domain'] = $domain['value'];
        $input['origin_host'] = $originHost['value'];
        $input['origin_port'] = $originPort['value'];
        if (($originScheme['exists'] ?? false) === true) {
            $input['origin_scheme'] = $originScheme['value'];
        }

        try {
            return ['data' => $this->service->create($input)];
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage(), 'status' => 502];
        }
    }

    public function update(string $domainId, array $input): ?array
    {
        if (array_key_exists('domain', $input)) {
            $domain = Validator::domain($input, 'domain');
            if (($domain['ok'] ?? false) !== true) {
                return $domain;
            }
            $input['domain'] = $domain['value'];
        }
        if (array_key_exists('origin_host', $input)) {
            $host = Validator::requiredString($input, 'origin_host', 255);
            if (($host['ok'] ?? false) !== true) {
                return $host;
            }
            $input['origin_host'] = $host['value'];
        }
        if (array_key_exists('origin_port', $input)) {
            $port = Validator::intRange($input, 'origin_port', 1, 65535);
            if (($port['ok'] ?? false) !== true) {
                return $port;
            }
        }
        if (array_key_exists('origin_scheme', $input)) {
            $scheme = Validator::enum($input, 'origin_scheme', ['http', 'https']);
            if (($scheme['ok'] ?? false) !== true) {
                return $scheme;
            }
            $input['origin_scheme'] = $scheme['value'];
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

    public function enableProxy(string $domainId): ?array
    {
        $domain = $this->service->setProxy($domainId, true);
        return $domain ? ['data' => $domain] : null;
    }

    public function disableProxy(string $domainId): ?array
    {
        $domain = $this->service->setProxy($domainId, false);
        return $domain ? ['data' => $domain] : null;
    }
}
