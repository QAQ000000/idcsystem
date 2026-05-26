<?php

namespace Tests\Feature;

use App\Modules\Admin\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_api_guide_and_openapi_json(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.api-docs.index'))
            ->assertOk()
            ->assertSee('API 文档')
            ->assertSee('/docs/api');

        config(['app.env' => 'local']);
        $this->get('/docs/api.json')
            ->assertOk()
            ->assertJsonPath('info.description', 'IDCSystem REST API 文档，覆盖认证、产品、服务、账单、账户和支付网关接口。');
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'api-doc-admin',
            'email' => 'api-doc-admin@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        $role = Role::query()->firstOrCreate(['name' => 'api-doc-admin', 'guard_name' => 'web']);
        $admin->syncRoles([$role]);
        $admin->givePermissionTo(Permission::query()->firstOrCreate(['name' => 'api_doc.view', 'guard_name' => 'web']));

        return $admin;
    }
}
