<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\User\Models\ClientTag;
use App\Modules\User\Models\TagAutoRule;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClientTagController extends Controller
{
    public function index()
    {
        $tags = ClientTag::query()->withCount('clients')->latest()->paginate(20);
        $rules = TagAutoRule::query()->with('tag')->latest()->paginate(20, ['*'], 'rules_page');

        return view('admin.client-tags.index', compact('tags', 'rules'));
    }

    public function store(Request $request)
    {
        ClientTag::query()->create($this->validatedTag($request));

        return redirect()->route('admin.client-tags.index')->with('status', '客户标签已创建');
    }

    public function update(Request $request, ClientTag $tag)
    {
        $tag->update($this->validatedTag($request, $tag));

        return redirect()->route('admin.client-tags.index')->with('status', '客户标签已更新');
    }

    public function destroy(ClientTag $tag)
    {
        if ($tag->system) {
            return redirect()->route('admin.client-tags.index')->with('error', '系统标签不能删除');
        }

        $tag->delete();

        return redirect()->route('admin.client-tags.index')->with('status', '客户标签已删除');
    }

    public function clients(ClientTag $tag)
    {
        $clients = $tag->clients()->latest('client_tag_pivot.tagged_at')->paginate(20);

        return view('admin.client-tags.clients', compact('tag', 'clients'));
    }

    public function storeRule(Request $request)
    {
        TagAutoRule::query()->create($this->validatedRule($request));

        return redirect()->route('admin.client-tags.index')->with('status', '自动规则已创建');
    }

    public function updateRule(Request $request, TagAutoRule $rule)
    {
        $rule->update($this->validatedRule($request));

        return redirect()->route('admin.client-tags.index')->with('status', '自动规则已更新');
    }

    private function validatedTag(Request $request, ?ClientTag $tag = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100', Rule::unique('client_tags', 'slug')->ignore($tag?->id)],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $slug = trim((string) ($data['slug'] ?? ''));
        $slug = $slug !== '' ? Str::slug($slug) : Str::slug($data['name']);
        $data['slug'] = $slug !== '' ? $slug : 'tag-' . Str::random(8);
        $data['system'] = (bool) ($tag?->system ?? false);

        return $data;
    }

    private function validatedRule(Request $request): array
    {
        $data = $request->validate([
            'client_tag_id' => ['required', 'integer', Rule::exists('client_tags', 'id')],
            'condition_type' => ['required', Rule::in(['total_spent', 'order_count', 'overdue_count', 'credit_balance'])],
            'operator' => ['required', Rule::in(['>', '>=', '<', '<=', '='])],
            'threshold' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['active'] = (bool) ($data['active'] ?? false);

        return $data;
    }
}
