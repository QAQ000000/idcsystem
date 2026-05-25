<?php

namespace App\Http\Controllers\Install;

use App\Http\Controllers\Controller;
use App\Services\AdminAuditService;
use App\Services\InstallService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class InstallController extends Controller
{
    public function index(InstallService $installer)
    {
        if ($installer->isInstalled()) {
            return redirect()->route('admin.login');
        }

        return view('install.check', $installer->status());
    }

    public function database(InstallService $installer)
    {
        if ($installer->isInstalled()) {
            return redirect()->route('admin.login');
        }

        $storedDatabaseReady = $installer->installationDatabaseReady();

        return view('install.database', [
            'checksPassed' => $installer->passesChecks(),
            'storedDatabaseReady' => $storedDatabaseReady,
            'storedDatabase' => $installer->storedDatabaseConfig(),
        ]);
    }

    public function saveDatabase(Request $request, InstallService $installer, AdminAuditService $audit)
    {
        if ($installer->isInstalled()) {
            return redirect()->route('admin.login');
        }

        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);
        $data = $this->withStoredDatabasePassword($data, $installer);

        try {
            $installer->runWithInstallationLock(function () use ($installer, $data): void {
                $installer->testDatabase($data);
                $installer->storeDatabaseConfig($data);
                $installer->runMigrationsAndSeeders();
                $installer->persistStoredDatabaseConfigAfterResponse();
            });
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors([
                'database' => '数据库初始化失败：' . $exception->getMessage(),
            ]);
        }

        $this->recordInstallAudit($audit, $request, 'install.database.save', [
            'host' => $data['host'],
            'port' => $data['port'],
            'database' => $data['database'],
            'username' => $data['username'],
        ]);

        return redirect()->route('install.admin')->with('status', '数据库配置和迁移已完成');
    }

    public function admin(InstallService $installer)
    {
        if ($installer->isInstalled()) {
            return redirect()->route('admin.login');
        }

        if (!$installer->installationDatabaseReady()) {
            return redirect()
                ->route('install.database')
                ->withErrors(['database' => '请先完成数据库连接和初始化。']);
        }

        return view('install.admin');
    }

    public function saveAdmin(Request $request, InstallService $installer, AdminAuditService $audit)
    {
        if ($installer->isInstalled()) {
            return redirect()->route('admin.login');
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:100'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'real_name' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $installer->runWithInstallationLock(function () use ($installer, $data): void {
                if (!$installer->installationDatabaseReady()) {
                    throw new RuntimeException('请先完成数据库连接和初始化。');
                }

                $installer->createAdmin($data);
                $installer->persistStoredDatabaseConfig();
                $installer->markInstalled(['admin' => $data['username']]);
            });
        } catch (RuntimeException $exception) {
            if ($installer->isInstalled()) {
                return redirect()->route('admin.login');
            }

            return redirect()
                ->route(str_contains($exception->getMessage(), '数据库') ? 'install.database' : 'install.admin')
                ->withErrors(['admin' => $exception->getMessage(), 'database' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors([
                'admin' => '管理员创建失败：' . $exception->getMessage(),
            ]);
        }

        $this->recordInstallAudit($audit, $request, 'install.admin.save', [
            'username' => $data['username'],
            'email' => $data['email'],
            'real_name' => $data['real_name'] ?? '',
        ]);

        return redirect()->route('install.finish');
    }

    private function recordInstallAudit(AdminAuditService $audit, Request $request, string $action, array $payload): void
    {
        try {
            $audit->record($request, $action, null, 'success', $payload);
        } catch (Throwable $exception) {
            Log::warning('Install audit log write failed.', [
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function withStoredDatabasePassword(array $data, InstallService $installer): array
    {
        $stored = $installer->storedDatabaseConfig();
        if (($data['password'] ?? '') !== '' || !$stored || ($stored['password'] ?? '') === '') {
            return $data;
        }

        foreach (['host', 'port', 'database', 'username'] as $key) {
            if ((string) ($data[$key] ?? '') !== (string) ($stored[$key] ?? '')) {
                return $data;
            }
        }

        $data['password'] = $stored['password'];

        return $data;
    }

    public function finish(InstallService $installer)
    {
        if (!$installer->isInstalled()) {
            return redirect()->route('install.index');
        }

        return view('install.finish');
    }
}
