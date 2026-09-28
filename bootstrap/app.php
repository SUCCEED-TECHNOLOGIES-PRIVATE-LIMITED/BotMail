<?php

use App\Http\Middleware\CheckBearerKey;
use App\Http\Middleware\CheckApiKey;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('bearer.key')
                ->prefix('v0')
                ->group(base_path('routes/v0.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'webhook/cloudflare',
            'webhook/resend',
        ]);

        $middleware->alias([
            'api.key' => CheckApiKey::class,
            'bearer.key' => CheckBearerKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
