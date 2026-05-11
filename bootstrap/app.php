<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\CheckRole;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // 1. CSRF se Helcim Webhook ko exclude karein
        $middleware->validateCsrfTokens(except: [
            'api/payment/webhook', 
            'api/website/lead'
        ]);

        // 2. Register route middleware aliases
        $middleware->alias([
            'role' => CheckRole::class,
        ]);

        // 3. Register global middleware
        $middleware->prepend(EnsureFrontendRequestsAreStateful::class);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();