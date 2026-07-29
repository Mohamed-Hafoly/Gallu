<?php

use App\Http\Middleware\SetLocaleFromHeader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->api(prepend: [
            SetLocaleFromHeader::class,
        ]);
        $middleware->web(prepend: [
            SetLocaleFromHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if ($request->expectsJson()) {
                $seconds = $e->getHeaders()['Retry-After'] ?? 60;

                return response()->json([
                    'message' => __('auth.throttle', ['seconds' => $seconds]),
                ], 429, $e->getHeaders());
            }
        });
    })->create();
