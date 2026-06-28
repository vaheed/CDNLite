<?php

use Illuminate\Support\Facades\Route;

Route::get('/v1/readiness', static fn (): array => [
    'status' => 'ok',
    'checks' => [
        'laravel' => 'ok',
    ],
]);
