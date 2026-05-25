<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Product\Models\CustomField;
use App\Modules\Product\Models\Product;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductCustomFieldController extends Controller
{
    public function store(Request $request, Product $product, AdminAuditService $audit)
    {
        $data = $this->validated($request);
        $field = CustomField::query()->create($data + [
            'type' => 'product',
            'rel_id' => $product->id,
        ]);
        $audit->record($request, 'product.custom_field.create', $product, 'success', [
            'field_id' => $field->id,
            'field_name' => $field->field_name,
        ]);

        return redirect()->route('admin.products.show', $product)->with('status', '自定义字段已创建');
    }

    public function update(Request $request, Product $product, CustomField $customField, AdminAuditService $audit)
    {
        abort_unless($this->belongsToProduct($customField, $product), 404);
        $data = $this->validated($request);
        $customField->update($data);
        $audit->record($request, 'product.custom_field.update', $product, 'success', [
            'field_id' => $customField->id,
            'field_name' => $customField->field_name,
        ]);

        return redirect()->route('admin.products.show', $product)->with('status', '自定义字段已保存');
    }

    public function destroy(Request $request, Product $product, CustomField $customField, AdminAuditService $audit)
    {
        abort_unless($this->belongsToProduct($customField, $product), 404);
        $fieldId = $customField->id;
        $customField->delete();
        $audit->record($request, 'product.custom_field.delete', $product, 'success', [
            'field_id' => $fieldId,
        ]);

        return redirect()->route('admin.products.show', $product)->with('status', '自定义字段已删除');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'field_name' => ['required', 'string', 'max:100'],
            'field_type' => ['required', 'string', Rule::in(['text', 'password', 'dropdown', 'select', 'textarea', 'checkbox'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'options' => ['nullable', 'string', 'max:5000'],
            'required' => ['nullable', 'boolean'],
            'admin_only' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $data['field_type'] = $data['field_type'] === 'select' ? 'dropdown' : $data['field_type'];
        $data['required'] = $request->boolean('required');
        $data['admin_only'] = $request->boolean('admin_only');
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    private function belongsToProduct(CustomField $field, Product $product): bool
    {
        return $field->type === 'product' && (int) $field->rel_id === (int) $product->id;
    }
}
