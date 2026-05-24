<?php

namespace Tests\Feature;

use App\Services\InstallService;
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

    public function test_installer_detects_initialized_database(): void
    {
        $this->useTemporaryInstallDatabase();
        Artisan::call('migrate', ['--force' => true, '--database' => 'mysql']);

        $installer = app(InstallService::class);

        $this->assertTrue($installer->databaseInitialized());
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
