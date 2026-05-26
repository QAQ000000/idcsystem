<?php

namespace App\Modules\User\Services;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Order;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientTag;
use App\Modules\User\Models\TagAutoRule;
use Illuminate\Support\Collection;

class ClientTagService
{
    public function attachTag(Client $client, ClientTag $tag): void
    {
        $client->tags()->syncWithoutDetaching([
            $tag->id => ['tagged_at' => now()],
        ]);
    }

    public function detachTag(Client $client, ClientTag $tag): void
    {
        $client->tags()->detach($tag->id);
    }

    public function applyAutoRules(Client $client): void
    {
        TagAutoRule::query()
            ->with('tag')
            ->where('active', true)
            ->chunkById(100, function ($rules) use ($client): void {
                foreach ($rules as $rule) {
                    if ($rule->tag && $this->evaluateRule($client, $rule)) {
                        $this->attachTag($client, $rule->tag);
                    }
                }
            });
    }

    public function evaluateRule(Client $client, TagAutoRule $rule): bool
    {
        $value = match ($rule->condition_type) {
            'total_spent' => (float) Invoice::query()
                ->where('client_id', $client->id)
                ->where('status', 'Paid')
                ->sum('total'),
            'order_count' => (float) Order::query()
                ->where('client_id', $client->id)
                ->count(),
            'overdue_count' => (float) Invoice::query()
                ->where('client_id', $client->id)
                ->where('status', 'Unpaid')
                ->whereNotNull('due_date')
                ->where('due_date', '<', now())
                ->count(),
            'credit_balance' => (float) $client->credit,
            default => 0.0,
        };

        $threshold = (float) $rule->threshold;

        return match ($rule->operator) {
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '=' => abs($value - $threshold) < 0.0001,
            default => false,
        };
    }

    public function getClientsByTag(ClientTag $tag): Collection
    {
        return $tag->clients()->latest('client_tag_pivot.tagged_at')->get();
    }
}
