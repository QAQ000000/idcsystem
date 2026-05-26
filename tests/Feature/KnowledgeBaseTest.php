<?php

namespace Tests\Feature;

use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Modules\Admin\Models\AdminUser;
use App\Modules\Finance\Models\Currency;
use App\Modules\Ticket\Models\TicketDepartment;
use App\Modules\User\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class KnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_kb_categories_and_articles(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.kb.categories.store'), [
                'name' => '常见问题',
                'slug' => 'faq',
                'description' => '常见问题分类',
                'sort_order' => 1,
                'active' => '1',
            ])
            ->assertRedirect(route('admin.kb.categories.index'))
            ->assertSessionHas('status', '知识库分类已创建');

        $category = KbCategory::query()->where('slug', 'faq')->firstOrFail();
        $this->assertTrue($category->active);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.kb.articles.store'), [
                'category_id' => $category->id,
                'title' => '如何重置密码',
                'slug' => 'reset-password',
                'content' => '登录页面可以重置密码。',
                'sort_order' => 1,
                'active' => '1',
            ])
            ->assertRedirect(route('admin.kb.articles.index'))
            ->assertSessionHas('status', '知识库文章已创建');

        $article = KbArticle::query()->where('slug', 'reset-password')->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->put(route('admin.kb.articles.update', $article), [
                'category_id' => $category->id,
                'title' => '如何找回密码',
                'slug' => 'recover-password',
                'content' => '通过邮箱验证码找回密码。',
                'sort_order' => 2,
                'active' => '1',
            ])
            ->assertRedirect(route('admin.kb.articles.index'))
            ->assertSessionHas('status', '知识库文章已保存');

        $this->assertDatabaseHas('kb_articles', [
            'id' => $article->id,
            'title' => '如何找回密码',
            'slug' => 'recover-password',
        ]);
        $this->assertDatabaseHas('admin_action_logs', [
            'action' => 'kb_article.update',
            'target_id' => $article->id,
            'result' => 'success',
        ]);
    }

    public function test_admin_cannot_delete_category_with_articles(): void
    {
        $category = $this->category();
        $this->article($category);

        $this->actingAs($this->admin(), 'admin')
            ->delete(route('admin.kb.categories.destroy', $category))
            ->assertRedirect(route('admin.kb.categories.index'))
            ->assertSessionHas('error', '该分类下仍有文章，不能删除');

        $this->assertDatabaseHas('kb_categories', ['id' => $category->id]);
    }

    public function test_client_can_browse_search_and_feedback_kb_articles(): void
    {
        $category = $this->category();
        $article = $this->article($category, [
            'title' => '云服务器无法连接',
            'slug' => 'server-connect',
            'content' => '请先检查安全组和 SSH 端口。',
        ]);

        $this->get(route('client.kb.index'))
            ->assertOk()
            ->assertSee('故障处理');

        $this->get(route('client.kb.search', ['q' => 'SSH']))
            ->assertOk()
            ->assertSee('云服务器无法连接');

        $this->get(route('client.kb.article', [$category, $article]))
            ->assertOk()
            ->assertSee('安全组');

        $this->assertSame(1, $article->fresh()->views);

        $this->post(route('client.kb.feedback', $article), ['helpful' => 1])
            ->assertRedirect()
            ->assertSessionHas('status', '感谢反馈');

        $this->assertSame(1, $article->fresh()->helpful_count);
    }

    public function test_ticket_create_page_can_fetch_kb_recommendations(): void
    {
        $client = $this->client();
        TicketDepartment::query()->create(['name' => '技术支持', 'allow_client_open' => true]);
        $category = $this->category();
        $this->article($category, [
            'title' => '数据库连接失败',
            'content' => '检查数据库地址、端口和账号密码。',
        ]);

        $this->actingAs($client, 'client')
            ->get(route('client.tickets.create'))
            ->assertOk()
            ->assertSee('可能有帮助的知识库文章');

        $this->actingAs($client, 'client')
            ->getJson(route('client.kb.search', ['q' => '数据库']))
            ->assertOk()
            ->assertJsonPath('data.0.title', '数据库连接失败');
    }

    private function category(array $overrides = []): KbCategory
    {
        return KbCategory::query()->create(array_merge([
            'name' => '故障处理',
            'slug' => 'troubleshooting-' . random_int(1000, 9999),
            'description' => '常见故障处理',
            'sort_order' => 0,
            'active' => true,
        ], $overrides));
    }

    private function article(KbCategory $category, array $overrides = []): KbArticle
    {
        return KbArticle::query()->create(array_merge([
            'category_id' => $category->id,
            'title' => '基础问题',
            'slug' => 'basic-' . random_int(1000, 9999),
            'content' => '基础帮助内容',
            'active' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    private function admin(): AdminUser
    {
        $admin = AdminUser::query()->create([
            'username' => 'kb-admin-' . random_int(1000, 9999),
            'email' => 'kb-admin-' . random_int(1000, 9999) . '@example.com',
            'password' => Hash::make('admin123456'),
            'status' => 1,
        ]);

        Role::query()->firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        $admin->syncRoles(['super-admin']);

        return $admin;
    }

    private function client(): Client
    {
        $currency = Currency::query()->firstOrCreate(
            ['code' => 'CNY'],
            ['prefix' => '¥', 'suffix' => '', 'exchange_rate' => 1, 'is_default' => true]
        );

        return Client::query()->create([
            'username' => 'kb-client',
            'email' => 'kb-client@example.com',
            'password' => Hash::make('client123456'),
            'status' => 1,
            'currency_id' => $currency->id,
        ]);
    }
}
