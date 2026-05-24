<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'group_id', 'name', 'description', 'type', 'pay_type', 'pay_method',
        'auto_setup', 'server_type', 'server_group_id', 'stock_control', 'stock_qty',
        'domain_config', 'password_config', 'hidden', 'retired', 'is_featured',
        'sort_order', 'api_type', 'upstream_api_id', 'upstream_product_id',
        'upstream_price_type', 'upstream_price_value',
    ];

    protected $casts = [
        'stock_control'          => 'boolean',
        'hidden'                 => 'boolean',
        'retired'                => 'boolean',
        'is_featured'            => 'boolean',
        'domain_config'          => 'array',
        'password_config'        => 'array',
        'upstream_price_value'   => 'decimal:2',
    ];

    public function group()
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    public function pricings()
    {
        return $this->hasMany(Pricing::class, 'rel_id')->where('type', 'product');
    }

    public function customFields()
    {
        return $this->hasMany(CustomField::class, 'rel_id')->where('type', 'product');
    }
}