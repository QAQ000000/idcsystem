<?php

namespace App\Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStockAlert extends Model
{
    protected $fillable = ['product_id', 'stock_qty', 'threshold', 'triggered_at', 'resolved_at'];

    protected $casts = [
        'triggered_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
