<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\LegacyFrontController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/readiness', [HealthController::class, 'readiness']);
Route::any('/{legacyPath}', LegacyFrontController::class)->where('legacyPath', '.*');
