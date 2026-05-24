<?php

namespace App\Services;

use App\Modules\Admin\Models\AdminUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class InstallService
{
    public function isInstalled(): bool
    {
        return File::exists($this->lockPath());
    }

    public function lockPath(): string
    {
        return (string) config('installer.lock_path', storage_path('app/install.lock'));
    }

    public function statePath(): string
    {
        return storage_path('app/install.state.json');
    }

    public function status(): array
    {
        return [
            'installed' => $this->isInstalled(),
            'checks' => $this->checks(),
            'lock_path' => $this->lockPath(),
        ];
    }

    public function checks(): array
    {
        $paths = [
            'storage' => storage_path(),
            'bootstrap_cache' => base_path('bootstrap/cache'),
            'env_file' => base_path('.env'),
        ];

        return [
            'php_version' => [
                'label' => 'PHP >= 8.3',
                'passed' => version_compare(PHP_VERSION, '8.3.0', '>='),
                'value' => PHP_VERSION,
            ],
            'pdo_mysql' => [
                'label' => 'PDO MySQL 扩展',
                'passed' => extension_loaded('pdo_mysql'),
                'value' => extension_loaded('pdo_mysql') ? 'loaded' : 'missing',
            ],
            'openssl' => [
                'label' => 'OpenSSL 扩展',
                'passed' => extension_loaded('openssl'),
                'value' => extension_loaded('openssl') ? 'loaded' : 'missing',
            ],
            'mbstring' => [
                'label' => 'Mbstring 扩展',
                'passed' => extension_loaded('mbstring'),
                'value' => extension_loaded('mbstring') ? 'loaded' : 'missing',
            ],
            'storage_writable' => [
                'label' => 'storage 可写',
                'passed' => is_writable($paths['storage']),
                'value' => $paths['storage'],
            ],
            'bootstrap_cache_writable' => [
                'label' => 'bootstrap/cache 可写',
                'passed' => is_writable($paths['bootstrap_cache']),
                'value' => $paths['bootstrap_cache'],
            ],
            'env_writable' => [
                'label' => '.env 可写',
                'passed' => File::exists($paths['env_file']) ? is_writable($paths['env_file']) : is_writable(base_path()),
                'value' => $paths['env_file'],
            ],
        ];
    }

    public function passesChecks(): bool
    {
        foreach ($this->checks() as $check) {
            if (!$check['passed']) {
                return false;
            }
        }

        return true;
    }

    public function saveDatabaseConfig(array $data): void
    {
        $this->ensureNotInstalled();
        $this->applyDatabaseConfig($data, true);
    }

    public function storeDatabaseConfig(array $data): void
    {
        $this->ensureNotInstalled();
        File::ensureDirectoryExists(dirname($this->statePath()));
        File::put($this->statePath(), json_encode([
            'database' => [
                'host' => $data['host'],
                'port' => (string) $data['port'],
                'database' => $data['database'],
                'username' => $data['username'],
                'password' => $data['password'] ?? '',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function storedDatabaseConfig(): ?array
    {
        if (!File::exists($this->statePath())) {
            return null;
        }

        $state = json_decode(File::get($this->statePath()), true) ?: [];

        return is_array($state['database'] ?? null) ? $state['database'] : null;
    }

    public function applyStoredDatabaseConfig(bool $persist = false): bool
    {
        $data = $this->storedDatabaseConfig();
        if (!$data) {
            return false;
        }

        $this->applyDatabaseConfig($data, $persist);

        return true;
    }

    public function persistStoredDatabaseConfigAfterResponse(): void
    {
        app()->terminating(function (): void {
            $this->applyStoredDatabaseConfig(true);
        });
    }

    private function applyDatabaseConfig(array $data, bool $persist): void
    {
        if ($persist) {
            $this->writeEnv([
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $data['host'],
            'DB_PORT' => (string) $data['port'],
            'DB_DATABASE' => $data['database'],
            'DB_USERNAME' => $data['username'],
            'DB_PASSWORD' => $data['password'] ?? '',
            ]);
        }

        Config::set('database.connections.mysql.host', $data['host']);
        Config::set('database.connections.mysql.port', (string) $data['port']);
        Config::set('database.connections.mysql.database', $data['database']);
        Config::set('database.connections.mysql.username', $data['username']);
        Config::set('database.connections.mysql.password', $data['password'] ?? '');
        Config::set('database.default', 'mysql');
        DB::purge('mysql');
        DB::reconnect('mysql');
    }

    public function testDatabase(array $data): bool
    {
        $this->ensureNotInstalled();
        $this->applyDatabaseConfig($data, false);
        DB::connection('mysql')->select('select 1');

        return true;
    }

    public function runMigrationsAndSeeders(): void
    {
        $this->ensureNotInstalled();
        $this->ensureMigrationRepository();
        $this->repairMigrationRepository();
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);
        Artisan::call('db:seed', ['--force' => true]);
    }

    public function databaseInitialized(): bool
    {
        try {
            return Schema::connection('mysql')->hasTable('migrations')
                && Schema::connection('mysql')->hasTable('admin_users')
                && Schema::connection('mysql')->hasTable('settings')
                && Schema::connection('mysql')->hasTable('plugins');
        } catch (\Throwable) {
            return false;
        }
    }

    public function createAdmin(array $data): AdminUser
    {
        $this->ensureNotInstalled();
        $this->applyStoredDatabaseConfig();

        return AdminUser::query()->updateOrCreate(
            ['username' => $data['username']],
            [
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'real_name' => $data['real_name'] ?? '系统管理员',
                'status' => 1,
            ]
        );
    }

    public function markInstalled(array $context = []): void
    {
        File::ensureDirectoryExists(dirname($this->lockPath()));
        File::put($this->lockPath(), json_encode([
            'installed_at' => now()->toIso8601String(),
            'app' => config('app.name'),
            'context' => $context,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        File::delete($this->statePath());
    }

    public function canDevRerun(): bool
    {
        return app()->environment(['local', 'testing']) && !$this->isInstalled();
    }

    public function ensureNotInstalled(): void
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('系统已安装，禁止重复执行初始化。');
        }
    }

    private function writeEnv(array $values): void
    {
        $path = base_path('.env');
        $content = File::exists($path) ? File::get($path) : '';

        foreach ($values as $key => $value) {
            $encoded = $this->encodeEnvValue($value);
            if (preg_match("/^{$key}=.*$/m", $content)) {
                $content = preg_replace("/^{$key}=.*$/m", "{$key}={$encoded}", $content);
            } else {
                $content .= PHP_EOL . "{$key}={$encoded}";
            }
        }

        File::put($path, ltrim($content));
    }

    private function encodeEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return preg_match('/\s|#|"|\'/', $value)
            ? '"' . str_replace('"', '\"', $value) . '"'
            : $value;
    }

    private function repairMigrationRepository(): void
    {
        $logged = DB::connection('mysql')->table('migrations')->pluck('migration')->all();
        $logged = array_flip($logged);
        $batch = ((int) DB::connection('mysql')->table('migrations')->max('batch')) ?: 1;
        $repairs = [];

        foreach (File::files(database_path('migrations')) as $file) {
            $migration = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            if (isset($logged[$migration])) {
                continue;
            }

            $table = $this->tableNameFromMigration($file->getRealPath());
            if ($table && Schema::connection('mysql')->hasTable($table)) {
                $repairs[] = [
                    'migration' => $migration,
                    'batch' => $batch,
                ];
            }
        }

        if ($repairs !== []) {
            DB::connection('mysql')->table('migrations')->insert($repairs);
        }
    }

    private function tableNameFromMigration(string $path): ?string
    {
        $content = File::get($path);

        return preg_match("/Schema::create\\(['\"]([^'\"]+)['\"]/", $content, $matches)
            ? $matches[1]
            : null;
    }

    private function ensureMigrationRepository(): void
    {
        if (!Schema::connection('mysql')->hasTable('migrations')) {
            Artisan::call('migrate:install', ['--database' => 'mysql']);
        }
    }
}
