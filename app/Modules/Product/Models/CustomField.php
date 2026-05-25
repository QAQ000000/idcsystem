<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class CustomField extends Model
{
    protected $fillable = [
        'type',
        'rel_id',
        'field_name',
        'field_type',
        'description',
        'options',
        'required',
        'admin_only',
        'sort_order',
    ];

    protected $casts = [
        'required' => 'boolean',
        'admin_only' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'rel_id')->where('type', 'product');
    }

    public function values()
    {
        return $this->hasMany(CustomFieldValue::class, 'field_id');
    }

    public function optionsList(): array
    {
        $raw = trim((string) $this->options);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('strval', $decoded), fn (string $value) => $value !== ''));
        }

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: []), fn (string $value) => $value !== ''));
    }
}
