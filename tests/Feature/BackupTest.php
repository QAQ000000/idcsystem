<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Modules\Admin\Models\AdminUser;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backup.path' => storage_path('framework/testing/backups'),
            'backup.file_paths' => [storage_path('framework/testing/uploaded')],
            'backup.enabled' => true,
        ]);
        File::deleteDirectory(storage_path('framework/testing/backups'));
        File::ensureDirectoryExists(storage_path('framework/testing/uploaded'));
        File::put(storage_path('framework/testing/uploaded/demo.txt'), 'backup me');
    }

    public function test_database_and_file_backup_commands_write_files_and_task_logs(): void
    {
        $this->artisan('backup:database')->assertExitCode(0);
        $this->artisan('backup:files')->assertExitCode(0);

        $this->assertSame(2, Backup::query()->where('status', 'completed')->count());
        foreach (Backup::query()->get() as $backup) {
            $this->assertFileExists($backup->file_path);
            $this->assertGreaterThan(0, $backup->file_size);
        }
        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'backup:database',
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('system_task_logs', [
            'task_name' => 'backup:files',
            'status' => 'success',
        ]);
    }

    public function test_cleanup_deletes_expired_backup_files(): void
    {
        $path = storage_path('framework/testing/backups/old.sql.gz');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, gzencode('old'));
        Backup::query()->create([
            'type' => 'database',
            'file_path' => $path,
            'file_size' => filesize($path),
            'status' => 'completed',
            'created_at' => now()->subDays(10),
        ]);

        $this->artisan('backup:cleanup', ['--days' => 1])->assertExitCode(0);

        $this->assertDatabaseCount('backups', 0);
        $this->assertFileDoesNotExist($path);
    }

    public function test_admin_can_trigger_download_restore_and_delete_backup(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.backups.database'))
            ->assertRedirect(route('admin.backups.index'))
            ->assertSessionHas('status', '数据库备份已完成。');

        $backup = Backup::query()->where('type', 'database')->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSee(basename($backup->file_path));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.backups.download', $backup))
            ->assertOk();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.backups.restore', $backup))
            ->assertRedirect(route('admin.backups.index'))
            ->assertSessionHas('status', '数据库备份恢复已执行。');

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.backups.destroy', $backup))
            ->assertRedirect(route('admin.backups.index'))
            ->assertSessionHas('status', '备份已删除。');

        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
        $this->assertFileDoesNotExist($backup->file_path);
    }

    public function test_file_backup_restore_is_rejected(): void
    {
        $backup = app(BackupService::class)->backupFiles();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('仅数据库备份支持恢复。');

        app(BackupService::class)->restore($backup);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'backup-admin-' . random_int(1000, 9999),
            'email' => 'backup-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        $role = Role::query()->firstOrCreate(['name' => 'backup-admin', 'guard_name' => 'web']);
        $admin->syncRoles([$role]);
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'backup.manage', 'guard_name' => 'web']));

        return $admin;
    }
}
