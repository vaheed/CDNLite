<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('cdnlite:runtime-maintenance', function (): int {
    $deleted = DB::table('edge_request_nonces')->where('expires_at', '<', time())->delete();
    $this->info("Pruned {$deleted} expired edge request nonces.");

    return self::SUCCESS;
})->purpose('Run lightweight CDNLite runtime maintenance tasks');

Schedule::command('cdnlite:runtime-maintenance')
    ->everyMinute()
    ->withoutOverlapping();
