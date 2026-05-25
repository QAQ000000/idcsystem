<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plugin as PluginModel;
use App\Plugins\Core\PluginManager;
use App\Services\AdminAuditService;
use App\Services\PluginConfigService;
use Illuminate\Http\Request;

class PluginController extends Controller
{
    public function index()
    {
        return view('admin.plugins.index', [
            'plugins' => PluginModel::query()->orderBy('type')->orderBy('name')->paginate(20),
            'pluginScans' => [
                'gateway' => app(PluginManager::class)->scan('gateway'),
                'email' => app(PluginManager::class)->scan('email'),
                'sms' => app(PluginManager::class)->scan('sms'),
                'server' => app(PluginManager::class)->scan('server'),
            ],
        ]);
    }

    public function install(Request $request, PluginManager $plugins, AdminAuditService $audit)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:gateway,email,sms,server'],
            'name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        $success = $plugins->install($data['type'], $data['name']);
        $plugin = PluginModel::query()->where('name', $data['name'])->first();
        $audit->record($request, 'plugin.install', $plugin, $success ? 'success' : 'failed', $data, $success ? null : '插件安装失败');

        if (!$success) {
            return redirect()->route('admin.plugins.index')->with('error', '插件安装失败');
        }

        return redirect()->route('admin.plugins.index')->with('status', '插件已安装');
    }

    public function uninstall(Request $request, string $name, PluginManager $plugins, AdminAuditService $audit)
    {
        $plugin = PluginModel::query()->where('name', $name)->first();
        $target = $plugin;
        $success = $plugin && $plugins->uninstall($name);
        $audit->record($request, 'plugin.uninstall', $target, $success ? 'success' : 'failed', [
            'name' => $name,
            'type' => $plugin?->type,
        ], $success ? null : '插件卸载失败');

        if (!$success) {
            return redirect()->route('admin.plugins.index')->with('error', '插件卸载失败');
        }

        return redirect()->route('admin.plugins.index')->with('status', '插件已卸载');
    }

    public function enable(Request $request, string $name, PluginManager $plugins, AdminAuditService $audit)
    {
        $success = $plugins->enable($name);
        $plugin = PluginModel::query()->where('name', $name)->first();
        $audit->record($request, 'plugin.enable', $plugin, $success ? 'success' : 'failed', ['name' => $name], $success ? null : '插件启用失败');

        if (!$success) {
            return redirect()->route('admin.plugins.index')->with('error', '插件启用失败');
        }

        return redirect()->route('admin.plugins.index')->with('status', '插件已启用');
    }

    public function disable(Request $request, string $name, PluginManager $plugins, AdminAuditService $audit)
    {
        $success = $plugins->disable($name);
        $plugin = PluginModel::query()->where('name', $name)->first();
        $audit->record($request, 'plugin.disable', $plugin, $success ? 'success' : 'failed', ['name' => $name], $success ? null : '插件禁用失败');

        if (!$success) {
            return redirect()->route('admin.plugins.index')->with('error', '插件禁用失败');
        }

        return redirect()->route('admin.plugins.index')->with('status', '插件已禁用');
    }

    public function config(string $name)
    {
        $plugin = PluginModel::query()->where('name', $name)->firstOrFail();
        $configFields = app(PluginManager::class)->configFields($plugin);

        return view('admin.plugins.config', compact('plugin', 'configFields'));
    }

    public function saveConfig(Request $request, string $name, PluginConfigService $configs, AdminAuditService $audit)
    {
        $data = $request->validate([
            'config' => ['nullable', 'array'],
        ]);

        $plugin = $configs->save($name, $data['config'] ?? []);
        $audit->record($request, 'plugin.config.save', $plugin, 'success', [
            'name' => $name,
            'config' => $data['config'] ?? [],
        ]);

        return redirect()->route('admin.plugins.config', $name)->with('status', '插件配置已保存');
    }
}
