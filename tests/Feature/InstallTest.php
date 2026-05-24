<?php

namespace Tests\Feature;

use App\Services\InstallService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InstallTest extends TestCase
{
    private string $lockPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockPath = storage_path('framework/testing/install.lock');
        config(['installer.lock_path' => $this->lockPath]);
        File::delete($this->lockPath);
    }

    protected function tearDown(): void
    {
        File::delete($this->lockPath);
        File::delete(storage_path('app/install.state.json'));
        File::delete(storage_path('framework/testing/install.sqlite'));

        parent::tearDown();
    }

    public function test_uninstalled_install_page_is_accessible(): void
    {
        $this->get(route('install.index'))
            ->assertOk()
            ->assertSee('环境检查');
    }

    public function test_install_write_entrypoints_are_rate_limited(): void
    {
        $this->assertContains('throttle:5,1', \Illuminate\Support\Facades\Route::getRoutes()->getByName('install.database.save')->middleware());
        $this->assertContains('throttle:5,1', \Illuminate\Support\Facades\Route::getRoutes()->getByName('install.admin.save')->middleware());
    }

    public function test_installed_install_page_redirects_to_admin_login(): void
    {
        File::ensureDirectoryExists(dirname($this->lockPath));
        File::put($this->lockPath, 'installed');

        $this->get(route('install.index'))
            ->assertRedirect(route('admin.login'));

        $this->get(route('install.database'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_install_status_logic_uses_lock_file(): void
    {
        $installer = app(InstallService::class);

        $this->assertFalse($installer->isInstalled());
        $this->assertTrue($installer->canDevRerun());

        $installer->markInstalled(['test' => true]);

        $this->assertTrue($installer->isInstalled());
        $this->assertFalse($installer->canDevRerun());
        $this->assertFileExists($this->lockPath);
    }

    public function test_installed_status_uses_database_marker_when_lock_file_is_missing(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('group', 50)->default('general');
            $table->timestamps();
        });

        DB::table('settings')->insert([
            'key' => 'system.installed_at',
            'value' => now()->toIso8601String(),
            'group' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFileDoesNotExist($this->lockPath);
        $this->assertTrue(app(InstallService::class)->isInstalled());

        $this->get(route('install.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_install_status_ignores_missing_settings_table(): void
    {
        $this->assertFalse(Schema::hasTable('settings'));
        $this->assertFalse(app(InstallService::class)->isInstalled());
    }

    public function test_installer_detects_initialized_database(): void
    {
        $this->useTemporaryInstallDatabase();
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);

        $installer = app(InstallService::class);

        $this->assertTrue($installer->databaseInitialized());
    }

    public function test_installer_does_not_treat_core_table_subset_as_initialized_database(): void
    {
        $this->useTemporaryInstallDatabase();
        Artisan::call('migrate:install', ['--database' => 'mysql']);
        Schema::connection('mysql')->create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('group', 50)->default('general');
            $table->timestamps();
        });
        Schema::connection('mysql')->create('plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('type', 50);
            $table->string('title', 100);
            $table->string('version', 20)->nullable();
            $table->string('entry')->nullable();
            $table->json('config')->nullable();
            $table->boolean('status')->default(false);
            $table->timestamps();
        });
        Schema::connection('mysql')->create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 100)->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->string('real_name', 100)->nullable();
            $table->boolean('status')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        $this->assertFalse(app(InstallService::class)->databaseInitialized());
    }

    public function test_installer_repairs_partial_migration_records(): void
    {
        $this->useTemporaryInstallDatabase();
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);

        $installer = app(InstallService::class);
        $connection = DB::connection('mysql');
        $connection->table('migrations')->where('migration', '0001_01_01_000002_create_jobs_table')->delete();

        $installer->runMigrationsAndSeeders();

        $this->assertTrue(Schema::connection('mysql')->hasTable('jobs'));
        $this->assertDatabaseHas('migrations', [
            'migration' => '0001_01_01_000002_create_jobs_table',
        ]);
    }

    public function test_installer_does_not_repair_multi_table_migration_when_only_first_table_exists(): void
    {
        $this->useTemporaryInstallDatabase();
        Artisan::call('migrate:install', ['--database' => 'mysql']);
        Schema::connection('mysql')->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        try {
            app(InstallService::class)->runMigrationsAndSeeders();
            $this->fail('Expected partially created jobs migration to fail instead of being marked as repaired.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('数据库已存在部分安装数据', $exception->getMessage());
        }

        $this->assertDatabaseMissing('migrations', [
            'migration' => '0001_01_01_000002_create_jobs_table',
        ]);
        $this->assertFalse(Schema::connection('mysql')->hasTable('job_batches'));
        $this->assertFalse(Schema::connection('mysql')->hasTable('failed_jobs'));
    }

    public function test_database_config_can_be_stored_without_writing_env_immediately(): void
    {
        $installer = app(InstallService::class);
        $env = base_path('.env');
        $original = File::exists($env) ? File::get($env) : '';

        $installer->storeDatabaseConfig([
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'install_test',
            'username' => 'install_user',
            'password' => 'secret',
        ]);

        $this->assertSame($original, File::exists($env) ? File::get($env) : '');
        $this->assertSame('install_test', $installer->storedDatabaseConfig()['database']);
    }

    public function test_admin_step_requires_initialized_database_state(): void
    {
        $this->get(route('install.admin'))
            ->assertRedirect(route('install.database'))
            ->assertSessionHasErrors('database');

        $this->post(route('install.admin.save'), [
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => 'admin123456',
            'password_confirmation' => 'admin123456',
        ])
            ->assertRedirect(route('install.database'))
            ->assertSessionHasErrors('database');
    }

    public function test_installation_database_ready_requires_state_and_initialized_tables(): void
    {
        $installer = app(InstallService::class);

        $this->assertFalse($installer->installationDatabaseReady());

        $this->useTemporaryInstallDatabase();
        $installer->storeDatabaseConfig([
            'host' => '',
            'port' => '0',
            'database' => storage_path('framework/testing/install.sqlite'),
            'username' => '',
            'password' => '',
        ]);

        $this->assertFalse($installer->installationDatabaseReady());

        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);

        $this->assertTrue($installer->installationDatabaseReady());
    }

    public function test_partial_install_schema_is_rejected_before_migrations(): void
    {
        $this->useTemporaryInstallDatabase();
        Artisan::call('migrate:install', ['--database' => 'mysql']);
        Schema::connection('mysql')->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        $installer = app(InstallService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('数据库已存在部分安装数据，请清空后重新安装。');

        $installer->runMigrationsAndSeeders();
    }

    public function test_installation_lock_rejects_nested_run(): void
    {
        $installer = app(InstallService::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('安装正在进行');

        $installer->runWithInstallationLock(function () use ($installer): void {
            $installer->runWithInstallationLock(fn () => true);
        });
    }

    public function test_mark_installed_removes_install_state(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('group', 50)->default('general');
            $table->timestamps();
        });

        $installer = app(InstallService::class);
        $installer->storeDatabaseConfig([
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'install_test',
            'username' => 'install_user',
            'password' => 'secret',
        ]);

        $this->assertFileExists($installer->statePath());

        $installer->markInstalled(['admin' => 'admin']);

        $this->assertFileExists($this->lockPath);
        $this->assertFileDoesNotExist($installer->statePath());
        $this->assertDatabaseHas('settings', [
            'key' => 'system.installed_at',
            'group' => 'system',
        ]);
    }

    public function test_install_save_steps_write_audit_logs(): void
    {
        $this->useTemporaryInstallDatabase();
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);

        $this->post(route('install.database.save'), [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => storage_path('framework/testing/install.sqlite'),
            'username' => 'root',
            'password' => '',
        ])->assertRedirect(route('install.admin'));

        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'install.database.save',
            'result' => 'success',
        ]);
    }

    private function useTemporaryInstallDatabase(): void
    {
        $database = storage_path('framework/testing/install.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::delete($database);
        File::put($database, '');

        Config::set('database.connections.mysql', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        Config::set('database.default', 'mysql');
        DB::purge('mysql');
        DB::reconnect('mysql');
    }
}
