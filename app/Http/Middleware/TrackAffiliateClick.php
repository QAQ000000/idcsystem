<?php

namespace App\Http\Middleware;

use App\Modules\User\Models\Affiliate;
use App\Modules\User\Services\AffiliateService;
use Closure;
use Illuminate\Http\Request;

class TrackAffiliateClick
{
    public function handle(Request $request, Closure $next): mixed
    {
        $code = $request->query('aff') ?: $request->query('ref');
        if (is_scalar($code)) {
            $code = trim((string) $code);
            if ($code !== '') {
                $affiliate = Affiliate::query()->where('code', $code)->where('status', 'active')->first();
                if ($affiliate) {
                    app(AffiliateService::class)->trackClick($affiliate, $request);
                    $request->session()->put('affiliate_ref', $affiliate->code);
                }
            }
        }

        return $next($request);
    }
}
