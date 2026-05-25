<?php

namespace App\Services;

use App\Models\Setting;
use App\Modules\Admin\Models\AdminUser;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Throwable;

class InstallService
{
    private const INSTALLED_MARKER_KEY = 'system.installed_at';
    private const REQUIRED_INSTALL_TABLES = [
        'migrations',
        'users',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'permissions',
        'roles',
        'model_has_permissions',
        'model_has_roles',
        'role_has_permissions',
        'personal_access_tokens',
        'admin_users',
        'admin_action_logs',
        'settings',
        'hooks',
        'hook_listeners',
        'plugins',
        'currencies',
        'client_groups',
        'clients',
        'contacts',
        'client_oauth',
        'product_groups',
        'products',
        'pricings',
        'config_groups',
        'config_options',
        'config_option_subs',
        'custom_fields',
        'custom_field_values',
        'promo_codes',
        'orders',
        'hosts',
        'host_config_options',
        'host_action_logs',
        'host_usage_snapshots',
        'upgrades',
        'invoices',
        'invoice_items',
        'accounts',
        'credits',
        'ticket_departments',
        'ticket_statuses',
        'tickets',
        'ticket_replies',
        'email_templates',
        'email_logs',
        'sms_templates',
        'sms_logs',
        'client_login_logs',
        'system_task_logs',
        'payment_refund_requests',
        'payment_attempts',
    ];

    private const PARTIAL_INSTALL_IGNORED_TABLES = [
        'migrations',
    ];

    public function isInstalled(): bool
    {
        return File::exists($this->lockPath()) || $this->hasDatabaseInstallMarker();
    }

    public function lockPath(): string
    {
        return (string) config('installer.lock_path', storage_path('app/install.lock'));
    }

    public function statePath(): string
    {
        return storage_path('app/install.state.json');
    }

    public function runningLockPath(): string
    {
        return storage_path('app/install.running.lock');
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
            'env_file' => $this->envPath(),
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

    public function runWithInstallationLock(callable $callback): mixed
    {
        File::ensureDirectoryExists(dirname($this->runningLockPath()));
        $handle = fopen($this->runningLockPath(), 'c');

        if ($handle === false) {
            throw new RuntimeException('无法创建安装互斥锁。');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new RuntimeException('安装正在进行，请稍后再试。');
        }

        try {
            return $callback();
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
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

    public function persistStoredDatabaseConfig(): bool
    {
        return $this->applyStoredDatabaseConfig(true);
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

        if ($this->databaseInitialized()) {
            $this->repairMigrationRepository();
            Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);
            Artisan::call('db:seed', ['--force' => true]);

            return;
        }

        // 安装过程中断线或页面重试时，数据库里可能已经落了一部分迁移。
        // 这里先修复 migration 仓库，让已经存在的表对应的迁移不再重复执行，
        // 再继续跑剩余迁移，避免 "table already exists" 直接中断安装。
        $this->repairMigrationRepository();
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);
        Artisan::call('db:seed', ['--force' => true]);
    }

    public function databaseInitialized(): bool
    {
        try {
            foreach (self::REQUIRED_INSTALL_TABLES as $table) {
                if (!Schema::connection('mysql')->hasTable($table)) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function installationDatabaseReady(): bool
    {
        if (!$this->applyStoredDatabaseConfig()) {
            return false;
        }

        return $this->databaseInitialized();
    }

    public function createAdmin(array $data): AdminUser
    {
        $this->ensureNotInstalled();
        $this->applyStoredDatabaseConfig();
        $role = $this->ensureSuperAdminRole();

        $admin = AdminUser::query()->updateOrCreate(
            ['username' => $data['username']],
            [
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'real_name' => $data['real_name'] ?? '系统管理员',
                'status' => 1,
            ]
        );
        $admin->syncRoles([$role]);

        return $admin;
    }

    public function markInstalled(array $context = []): void
    {
        $installedAt = now()->toIso8601String();

        File::ensureDirectoryExists(dirname($this->lockPath()));
        $written = File::put($this->lockPath(), json_encode([
            'installed_at' => $installedAt,
            'app' => config('app.name'),
            'context' => $context,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ($written === false) {
            throw new RuntimeException('安装锁写入失败。');
        }

        $this->writeDatabaseInstallMarker($installedAt);
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
        $path = $this->envPath();
        $content = File::exists($path) ? File::get($path) : '';

        foreach ($values as $key => $value) {
            $encoded = $this->encodeEnvValue($value);
            if (preg_match("/^{$key}=.*$/m", $content)) {
                $content = preg_replace("/^{$key}=.*$/m", "{$key}={$encoded}", $content);
            } else {
                $content .= PHP_EOL . "{$key}={$encoded}";
            }
        }

        if (File::put($path, ltrim($content)) === false) {
            throw new RuntimeException('.env 配置写入失败。');
        }
    }

    private function envPath(): string
    {
        return (string) config('installer.env_path', base_path('.env'));
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

            $tables = $this->tableNamesFromMigration($file->getRealPath());
            $tableAlterations = $this->tableAlterationsFromMigration($file->getRealPath());
            if ($tables !== [] && $this->allTablesExist($tables) && $this->allTableAlterationsExist($tableAlterations)) {
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

    private function hasPartialInstallSchema(): bool
    {
        $existing = [];

        foreach (self::REQUIRED_INSTALL_TABLES as $table) {
            if (in_array($table, self::PARTIAL_INSTALL_IGNORED_TABLES, true)) {
                continue;
            }

            if (Schema::connection('mysql')->hasTable($table)) {
                $existing[] = $table;
            }
        }

        if ($existing === []) {
            return false;
        }

        return count($existing) !== count(self::REQUIRED_INSTALL_TABLES);
    }

    private function tableNamesFromMigration(string $path): array
    {
        $content = File::get($path);

        preg_match_all("/Schema::create\\(['\"]([^'\"]+)['\"]/", $content, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private function tableAlterationsFromMigration(string $path): array
    {
        $content = File::get($path);

        preg_match_all("/Schema::table\\(['\"]([^'\"]+)['\"]/", $content, $tables);
        if (($tables[1] ?? []) === []) {
            return [];
        }

        preg_match_all("/\\\$table->(?:[a-zA-Z0-9_]+)\\(['\"]([^'\"]+)['\"]/", $content, $columns);
        if (($columns[1] ?? []) === []) {
            return [];
        }

        $alterations = [];
        foreach (array_unique($tables[1]) as $table) {
            $alterations[$table] = array_values(array_unique($columns[1]));
        }

        return $alterations;
    }

    private function allTablesExist(array $tables): bool
    {
        foreach ($tables as $table) {
            if (!Schema::connection('mysql')->hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function allTableAlterationsExist(array $alterations): bool
    {
        foreach ($alterations as $table => $columns) {
            if (!Schema::connection('mysql')->hasTable($table)) {
                return false;
            }

            foreach ($columns as $column) {
                if (!Schema::connection('mysql')->hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function ensureMigrationRepository(): void
    {
        if (!Schema::connection('mysql')->hasTable('migrations')) {
            Artisan::call('migrate:install', ['--database' => 'mysql']);
        }
    }

    private function hasDatabaseInstallMarker(): bool
    {
        try {
            if (!Schema::hasTable('settings')) {
                return false;
            }

            return Setting::query()
                ->where('key', self::INSTALLED_MARKER_KEY)
                ->whereNotNull('value')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function writeDatabaseInstallMarker(string $installedAt): void
    {
        try {
            if (!Schema::hasTable('settings')) {
                return;
            }

            Setting::query()->updateOrCreate(
                ['key' => self::INSTALLED_MARKER_KEY],
                ['value' => $installedAt, 'group' => 'system']
            );
            app(SettingsService::class)->clearCache();
        } catch (Throwable) {
            // 安装锁仍是最终兜底标记；数据库标记失败不能让完成页卡死在半安装状态。
        }
    }

    private function ensureSuperAdminRole(): Role
    {
        $role = Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        if (Schema::hasTable('permissions')) {
            $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
        }

        return $role;
    }
}
