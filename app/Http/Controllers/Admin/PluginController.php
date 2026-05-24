<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plugin as PluginModel;
use App\Plugins\Core\PluginManager;
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
            ],
        ]);
    }

    public function install(Request $request, PluginManager $plugins)
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $plugins->install($data['type'], $data['name']);

        return redirect()->route('admin.plugins.index')->with('status', '插件已安装');
    }

    public function uninstall(string $name, PluginManager $plugins)
    {
        $plugins->uninstall($name);

        return redirect()->route('admin.plugins.index')->with('status', '插件已卸载');
    }

    public function enable(string $name, PluginManager $plugins)
    {
        $plugins->enable($name);

        return redirect()->route('admin.plugins.index')->with('status', '插件已启用');
    }

    public function disable(string $name, PluginManager $plugins)
    {
        $plugins->disable($name);

        return redirect()->route('admin.plugins.index')->with('status', '插件已禁用');
    }

    public function config(string $name)
    {
        $plugin = PluginModel::query()->where('name', $name)->firstOrFail();

        return view('admin.plugins.config', compact('plugin'));
    }

    public function saveConfig(Request $request, string $name, PluginConfigService $configs)
    {
        $data = $request->validate([
            'config' => ['nullable', 'array'],
        ]);

        $configs->save($name, $data['config'] ?? []);

        return redirect()->route('admin.plugins.config', $name)->with('status', '插件配置已保存');
    }
}
