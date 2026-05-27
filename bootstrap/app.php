<?php

use App\Http\Middleware\CheckAdminStatus;
use App\Http\Middleware\CheckClientStatus;
use App\Http\Middleware\PerformanceMonitor;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTimezone;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        then: function (): void {
            $adminRoutes = __DIR__.'/../routes/admin.php';

            if (file_exists($adminRoutes)) {
                Route::middleware('web')->group($adminRoutes);
            }
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            SetTimezone::class,
            \App\Http\Middleware\TrackAffiliateClick::class,
            PerformanceMonitor::class,
        ]);
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        $middleware->api(append: [
            PerformanceMonitor::class,
        ]);
        $middleware->alias([
            'admin.status' => CheckAdminStatus::class,
            'client.status' => CheckClientStatus::class,
            'admin.permission' => \App\Http\Middleware\EnsureAdminPermission::class,
            'api.ability' => \App\Http\Middleware\CheckApiAbility::class,
            'api.ip_whitelist' => \App\Http\Middleware\CheckApiIpWhitelist::class,
            'api.log' => \App\Http\Middleware\LogApiRequest::class,
            'api.quota' => \App\Http\Middleware\CheckApiQuota::class,
            'api.signature' => \App\Http\Middleware\VerifyApiSignature::class,
            'api.size' => \App\Http\Middleware\LimitRequestSize::class,
        ]);
        $middleware->redirectGuestsTo(function ($request) {
            return $request->is('admin/*') || $request->is('admin')
                ? route('admin.login')
                : route('client.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
