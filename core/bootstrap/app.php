<?php

use Illuminate\Foundation\Application;
use App\Http\Middleware\AdminBearerAuth;
use App\Http\Middleware\CdnliteCors;
use App\Http\Middleware\EdgeSignatureAuth;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CdnliteCors::class);
        $middleware->alias([
            'admin.auth' => AdminBearerAuth::class,
            'edge.auth' => EdgeSignatureAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
