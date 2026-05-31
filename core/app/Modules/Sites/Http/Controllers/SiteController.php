<?php

namespace App\Modules\Sites\Http\Controllers;

use App\Modules\Sites\Services\SiteService;

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
        foreach (['name', 'domain', 'origin_host'] as $field) {
            if (empty($input[$field])) {
                return ['error' => $field . '_required', 'status' => 422];
            }
        }

        if ($this->service->findByDomain((string) $input['domain']) !== null) {
            return ['error' => 'domain_already_exists', 'status' => 422];
        }

        return ['data' => $this->service->create($input)];
    }

    public function update(int $siteId, array $input): ?array
    {
        if (isset($input['domain'])) {
            $existing = $this->service->findByDomain((string) $input['domain']);
            if ($existing !== null && (int) $existing['id'] !== $siteId) {
                return ['error' => 'domain_already_exists', 'status' => 422];
            }
        }

        $site = $this->service->update($siteId, $input);
        return $site ? ['data' => $site] : null;
    }

    public function delete(int $siteId): array
    {
        return $this->service->delete($siteId)
            ? ['ok' => true]
            : ['error' => 'site_not_found', 'status' => 404];
    }

    public function enableProxy(int $siteId): ?array
    {
        $site = $this->service->setProxy($siteId, true);
        return $site ? ['data' => $site] : null;
    }

    public function disableProxy(int $siteId): ?array
    {
        $site = $this->service->setProxy($siteId, false);
        return $site ? ['data' => $site] : null;
    }
}
