<?php

namespace App\Modules\Sites\Http\Controllers;

use App\Modules\Sites\Services\SiteService;
use App\Support\Validator;

class SiteController
{
    public function __construct(private SiteService $service)
    {
    }

    public function index(): array
    {
        return ['data' => $this->service->all()];
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

    public function update(string $siteId, array $input): ?array
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

        if (isset($input['domain'])) {
            $existing = $this->service->findByDomain((string) $input['domain']);
            if ($existing !== null && (string) $existing['id'] !== $siteId) {
                return ['error' => 'domain_already_exists', 'status' => 422];
            }
        }

        $site = $this->service->update($siteId, $input);
        return $site ? ['data' => $site] : null;
    }

    public function delete(string $siteId): array
    {
        return $this->service->delete($siteId)
            ? ['ok' => true]
            : ['error' => 'site_not_found', 'status' => 404];
    }

    public function enableProxy(string $siteId): ?array
    {
        $site = $this->service->setProxy($siteId, true);
        return $site ? ['data' => $site] : null;
    }

    public function disableProxy(string $siteId): ?array
    {
        $site = $this->service->setProxy($siteId, false);
        return $site ? ['data' => $site] : null;
    }
}
