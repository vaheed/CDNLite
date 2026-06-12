<?php

namespace App\Modules\Dns\Http\Controllers;

use App\Modules\Dns\Services\GeoRoutingService;

class EdgeNetworkController
{
    public function __construct(
        private GeoRoutingService $geo = new GeoRoutingService()
    ) {
    }

    public function countries(): array
    {
        return ['data' => $this->geo->countries()];
    }

}
