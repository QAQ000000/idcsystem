<?php

namespace Database\Seeders;

use App\Modules\Admin\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $role = Role::query()->updateOrCreate(
            ['name' => 'super-admin', 'guard_name' => 'web'],
            ['name' => 'super-admin', 'guard_name' => 'web']
        );

        $admin = AdminUser::query()->updateOrCreate(
            ['username' => 'admin'],
            [
                'email' => 'admin@example.com',
                'password' => Hash::make('admin123456'),
                'real_name' => '系统管理员',
                'phone' => null,
                'status' => 1,
            ]
        );

        $admin->syncRoles([$role]);
        $role->syncPermissions(Permission::query()->where('guard_name', 'web')->get());
    }
}
