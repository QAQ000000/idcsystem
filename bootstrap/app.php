<?php

use App\Http\Middleware\CheckAdminStatus;
use App\Http\Middleware\CheckClientStatus;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        then: function (): void {
            $adminRoutes = __DIR__.'/../routes/admin.php';

            if (file_exists($adminRoutes)) {
                Route::middleware('web')->group($adminRoutes);
            }
        },
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('host:sync-usage')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('host:send-due-reminders --days=7')->daily()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.status' => CheckAdminStatus::class,
            'client.status' => CheckClientStatus::class,
            'admin.permission' => \App\Http\Middleware\EnsureAdminPermission::class,
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
