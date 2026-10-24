<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\RequireApiScope;
use App\Http\Middleware\TrackApiRequest;
use App\Http\Responses\ApiErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware(SubstituteBindings::class)->group(base_path('routes/mail.php'));

            // The API authenticates before route model binding, so an unauthenticated caller cannot probe which ids exist.
            Route::prefix('api/v1')
                ->name('api.v1.')
                ->middleware([TrackApiRequest::class, AuthenticateApiKey::class, 'throttle:api', SubstituteBindings::class])
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.scope' => RequireApiScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(
            fn (Throwable $exception, Request $request) => $request->is('api/*') ? ApiErrorResponse::fromThrowable($exception, $request) : null,
        );
    })->create();
