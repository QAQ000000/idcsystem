<?php

namespace Tests\Feature;

use App\Services\InstallService;
use Illuminate\Support\Facades\File;
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
}
