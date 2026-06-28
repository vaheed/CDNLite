<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'health']);
Route::get('/cdn-health', [HealthController::class, 'cdnHealth']);
Route::get('/ready', [HealthController::class, 'ready']);
