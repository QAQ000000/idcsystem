<?php

namespace App\Models;

use App\Modules\Finance\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PaymentRefundRequest extends Model
{
    private const SENSITIVE_KEYWORDS = [
        'password',
        'secret',
        'token',
        'credential',
        'access_key',
        'private_key',
        'signature',
        'sign',
        'key',
    ];

    protected $fillable = [
        'account_id',
        'invoice_id',
        'gateway',
        'gateway_trans_id',
        'amount',
        'status',
        'error',
        'gateway_refund_succeeded_at',
        'finished_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_refund_succeeded_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    protected static function booted(): void
    {
        static::saving(function (PaymentRefundRequest $request): void {
            $request->error = $request->error === null ? null : static::maskSensitiveText($request->error);
        });
    }

    private static function maskSensitiveText(string $value): string
    {
        foreach (self::SENSITIVE_KEYWORDS as $key) {
            $value = preg_replace(
                '/(' . preg_quote($key, '/') . ')\s*([=:])\s*([^\s,;]+)/i',
                '$1$2[FILTERED]',
                $value
            ) ?? $value;
        }

        return Str::limit($value, 2000, '...');
    }
}
