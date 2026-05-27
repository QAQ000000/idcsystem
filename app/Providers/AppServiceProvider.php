<?php

namespace App\Providers;

use App\Services\ThemeService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('viewApiDocs', function ($user = null) {
            return auth('admin')->check();
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        Blade::directive('usertime', function ($expression): string {
            return "<?php echo e(userTime($expression)); ?>";
        });

        Blade::directive('usertimezone', function (): string {
            return "<?php echo e(current_timezone()); ?>";
        });

        $theme = app(ThemeService::class)->active();
        $themePaths = [resource_path("themes/{$theme}")];
        if ($theme !== 'default') {
            $themePaths[] = resource_path('themes/default');
        }

        View::addNamespace('theme', $themePaths);
        View::addNamespace('themes', resource_path('themes'));

        if ((bool) config('performance.log_slow_queries', true)) {
            DB::listen(function ($query): void {
                $threshold = (int) config('performance.slow_query_ms', 100);
                if ($query->time <= $threshold) {
                    return;
                }

                Log::warning('Slow query detected', [
                    'connection' => $query->connectionName,
                    'sql' => $query->sql,
                    'time_ms' => $query->time,
                ]);
            });
        }
    }
}
