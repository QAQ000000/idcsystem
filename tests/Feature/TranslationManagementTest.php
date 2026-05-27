<?php

namespace Tests\Feature;

use App\Models\Translation;
use App\Modules\Admin\Models\AdminUser;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TranslationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(lang_path('zz'));

        parent::tearDown();
    }

    public function test_translation_service_imports_language_files(): void
    {
        $count = app(TranslationService::class)->importFromFiles(['zh_CN', 'en']);

        $this->assertGreaterThanOrEqual(6, $count);
        $this->assertDatabaseHas('translations', [
            'locale' => 'en',
            'group' => 'messages',
            'key' => 'auth.login_title',
            'value' => 'Client Login',
            'translated_by' => 'manual',
        ]);
    }

    public function test_translation_service_exports_database_rows_to_language_files(): void
    {
        Translation::query()->create([
            'locale' => 'zz',
            'group' => 'messages',
            'key' => 'auth.login_title',
            'value' => 'ZZ Login',
            'is_translated' => true,
            'translated_by' => 'manual',
        ]);

        $count = app(TranslationService::class)->exportToFiles(['zz']);

        $this->assertSame(1, $count);
        $this->assertFileExists(lang_path('zz/messages.php'));
        $messages = require lang_path('zz/messages.php');
        $this->assertSame('ZZ Login', $messages['auth']['login_title']);
    }

    public function test_admin_can_import_edit_and_auto_translate_records(): void
    {
        $admin = $this->admin();
        Translation::query()->create([
            'locale' => 'en',
            'group' => 'messages',
            'key' => 'demo.title',
            'value' => 'Demo Title',
            'is_translated' => true,
            'translated_by' => 'manual',
        ]);
        $translation = Translation::query()->create([
            'locale' => 'zh_CN',
            'group' => 'messages',
            'key' => 'demo.title',
            'value' => '演示标题',
            'is_translated' => true,
            'translated_by' => 'manual',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.translations.index', ['locale' => 'zh_CN', 'search' => 'demo.title']))
            ->assertOk()
            ->assertSee('翻译管理')
            ->assertSee('demo.title');

        $this->actingAs($admin, 'admin')
            ->put(route('admin.translations.update', $translation), ['value' => '新的标题'])
            ->assertRedirect()
            ->assertSessionHas('status', '翻译已更新');
        $this->assertSame('新的标题', $translation->fresh()->value);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.translations.auto-translate'), [
                'from' => 'en',
                'to' => 'ja',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '已自动翻译 1 条记录');

        $this->assertDatabaseHas('translations', [
            'locale' => 'ja',
            'group' => 'messages',
            'key' => 'demo.title',
            'value' => 'Demo Title',
            'translated_by' => 'fallback',
        ]);
    }

    public function test_admin_import_endpoint_loads_available_locale_files(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.translations.import'))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('translations', [
            'locale' => 'zh_CN',
            'group' => 'messages',
            'key' => 'language.ja',
        ]);
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'translation-admin-' . random_int(1000, 9999),
            'email' => 'translation-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }
}
