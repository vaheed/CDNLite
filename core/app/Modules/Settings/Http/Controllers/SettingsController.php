<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Dns\Services\PowerDnsService;
use App\Modules\Settings\Repositories\SettingsRepository;

class SettingsController
{
    public function __construct(private SettingsRepository $repository)
    {
    }

    public function index(): array
    {
        return ['groups' => $this->repository->groups()];
    }

    public function show(string $group): array
    {
        return $this->repository->group($group);
    }

    public function update(string $group, array $body, ?string $actor): array
    {
        $values = isset($body['values']) && is_array($body['values']) ? $body['values'] : $body;
        return $this->repository->patch($group, $values, $actor);
    }

    public function validate(array $body): array
    {
        $group = (string) ($body['group'] ?? '');
        $values = isset($body['values']) && is_array($body['values']) ? $body['values'] : [];
        return $this->repository->validate($group, $values);
    }

    public function testPowerDns(): array
    {
        return (new PowerDnsService($this->repository))->healthCheck();
    }
}
