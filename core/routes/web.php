<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', static fn (): array => [
    'ok' => true,
    'time' => time(),
]);

Route::get('/cdn-health', static fn (): array => [
    'status' => 'ok',
    'checks' => [
        'laravel' => 'ok',
    ],
]);

Route::get('/ready', static function (): array {
    $checks = [
        'postgres' => 'ok',
        'schema' => 'ok',
        'config_generation' => 'warn',
    ];

    try {
        DB::select('SELECT 1');
    } catch (Throwable) {
        $checks['postgres'] = 'fail';
    }

    return [
        'status' => in_array('fail', $checks, true) ? 'fail' : 'ok',
        'checks' => $checks,
    ];
});
