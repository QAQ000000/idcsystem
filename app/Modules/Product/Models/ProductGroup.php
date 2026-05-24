<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class ProductGroup extends Model
{
    protected $fillable = ['parent_id', 'name', 'description', 'sort_order', 'hidden'];

    protected $casts = ['hidden' => 'boolean'];

    public function products()
    {
        return $this->hasMany(Product::class, 'group_id');
    }

    public function parent()
    {
        return $this->belongsTo(ProductGroup::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(ProductGroup::class, 'parent_id');
    }
}