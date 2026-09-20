<?php

use Illuminate\Foundation\Application;
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
        // Lets the Nuxt dev server on :3000 call this API cross-origin.
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Without this, a validation failure on an API route answers with a 302
        // back to the referrer — Laravel's HTML-form behaviour — instead of a
        // 422 carrying the errors. A browser fetch that sets `Accept:
        // application/json` gets the right thing either way, so the redirect
        // only shows up for callers that do not, which is exactly when it is
        // most confusing. Everything under api/* is JSON, so say so once here.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
