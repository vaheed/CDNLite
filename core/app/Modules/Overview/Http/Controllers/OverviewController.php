<?php
namespace App\Modules\Overview\Http\Controllers;

use App\Modules\Overview\Services\OverviewService;

class OverviewController
{
    public function __construct(private OverviewService $service) {}
    public function index(): array { return $this->service->overview(); }
    public function warnings(): array { return $this->service->warnings(); }
}
