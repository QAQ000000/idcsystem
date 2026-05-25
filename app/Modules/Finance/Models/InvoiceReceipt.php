<?php

namespace App\Modules\Finance\Models;

use App\Modules\User\Models\Client;
use Illuminate\Database\Eloquent\Model;

class InvoiceReceipt extends Model
{
    protected $fillable = [
        'client_id',
        'invoice_id',
        'type',
        'title',
        'tax_number',
        'bank_name',
        'bank_account',
        'company_address',
        'company_phone',
        'email',
        'status',
        'admin_notes',
        'issued_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
