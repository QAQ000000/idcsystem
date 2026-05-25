<?php

namespace App\Modules\Product\Models;

use App\Modules\Order\Models\Host;
use Illuminate\Database\Eloquent\Model;

class CustomFieldValue extends Model
{
    protected $fillable = [
        'field_id',
        'rel_id',
        'value',
    ];

    public function field()
    {
        return $this->belongsTo(CustomField::class, 'field_id');
    }

    public function host()
    {
        return $this->belongsTo(Host::class, 'rel_id');
    }
}
