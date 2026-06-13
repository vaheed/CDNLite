<?php

namespace App\Modules\Dns\Http\Controllers;

use App\Modules\Dns\Services\DnsOperationsService;

class DnsOperationsController
{
    public function __construct(private DnsOperationsService $service = new DnsOperationsService())
    {
    }

    public function status(): array { return ['data' => $this->service->status()]; }
    public function zones(): array { return ['data' => $this->service->zones()]; }
    public function desired(?string $zone): array { return ['data' => $this->service->desired($zone)]; }
    public function actual(string $zone): array { return ['data' => $this->service->actual($zone)]; }
    public function dryRun(): array { return ['data' => $this->service->dryRun()]; }
    public function forceSync(): array { return ['data' => $this->service->forceSync()]; }
    public function domainStatus(string $domainId): array { return $this->service->domainStatus($domainId); }
}
