<?php

namespace App\Modules\User\Services;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Order\Models\Host;
use App\Modules\Order\Models\Order;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientSegment;
use App\Modules\User\Models\ClientSegmentMember;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClientSegmentService
{
    private const OPERATORS = ['>', '>=', '<', '<=', '='];

    public function calculate(ClientSegment $segment): int
    {
        if (!$segment->isDynamic()) {
            return $this->refreshCount($segment);
        }

        $clientIds = $this->matchingClientIds($segment->rules ?? []);

        DB::transaction(function () use ($segment, $clientIds): void {
            ClientSegmentMember::query()->where('segment_id', $segment->id)->delete();

            if ($clientIds->isNotEmpty()) {
                ClientSegmentMember::query()->insert($clientIds->map(fn (int $id): array => [
                    'segment_id' => $segment->id,
                    'client_id' => $id,
                    'added_at' => now(),
                ])->all());
            }

            $segment->update([
                'clients_count' => $clientIds->count(),
                'last_calculated_at' => now(),
            ]);
        });

        return $clientIds->count();
    }

    public function addToSegment(ClientSegment $segment, array $clientIds): int
    {
        $this->ensureStatic($segment);
        $ids = $this->validClientIds($clientIds);
        if ($ids === []) {
            return $this->refreshCount($segment);
        }

        ClientSegmentMember::query()->insertOrIgnore(collect($ids)->map(fn (int $id): array => [
            'segment_id' => $segment->id,
            'client_id' => $id,
            'added_at' => now(),
        ])->all());

        return $this->refreshCount($segment);
    }

    public function removeFromSegment(ClientSegment $segment, array $clientIds): int
    {
        $this->ensureStatic($segment);
        $ids = $this->validClientIds($clientIds);
        if ($ids !== []) {
            ClientSegmentMember::query()
                ->where('segment_id', $segment->id)
                ->whereIn('client_id', $ids)
                ->delete();
        }

        return $this->refreshCount($segment);
    }

    public function refreshDynamicSegments(): int
    {
        $count = 0;
        ClientSegment::query()
            ->where('type', 'dynamic')
            ->chunkById(50, function ($segments) use (&$count): void {
                foreach ($segments as $segment) {
                    $this->calculate($segment);
                    $count++;
                }
            });

        return $count;
    }

    public function normalizeRules(mixed $rules): array
    {
        if (is_string($rules)) {
            $rules = trim($rules) === '' ? [] : json_decode($rules, true);
        }

        if (!is_array($rules)) {
            throw new InvalidArgumentException('动态分群规则必须是 JSON 数组。');
        }

        return collect($rules)
            ->map(function ($rule): array {
                if (!is_array($rule)) {
                    throw new InvalidArgumentException('动态分群规则格式不正确。');
                }

                $field = (string) ($rule['field'] ?? '');
                $operator = (string) ($rule['operator'] ?? '');
                if (!in_array($field, $this->supportedFields(), true) || !in_array($operator, self::OPERATORS, true)) {
                    throw new InvalidArgumentException('动态分群规则字段或运算符不支持。');
                }

                return [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $rule['value'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function matchingClientIds(array $rules)
    {
        return Client::query()
            ->where('status', 1)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(function (int $id) use ($rules): bool {
                foreach ($rules as $rule) {
                    if (!in_array($id, $this->idsForRule(is_array($rule) ? $rule : []), true)) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    private function idsForRule(array $rule): array
    {
        $field = (string) ($rule['field'] ?? '');
        $operator = (string) ($rule['operator'] ?? '=');
        $value = $rule['value'] ?? null;

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException('动态分群规则运算符不支持。');
        }

        return match ($field) {
            'credit_balance' => Client::query()
                ->where('status', 1)
                ->where('credit', $operator, (float) $value)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            'registered_days' => Client::query()
                ->where('status', 1)
                ->where('created_at', $this->dateOperator($operator), now()->subDays((int) $value))
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            'total_spent' => $this->aggregateIds(Invoice::class, 'client_id', 'total', $operator, $value, fn ($q) => $q->where('status', 'Paid')),
            'order_count' => $this->countIds(Order::class, 'client_id', $operator, (int) $value),
            'active_hosts_count' => $this->countIds(Host::class, 'client_id', $operator, (int) $value, fn ($q) => $q->where('status', 'Active')),
            'has_tag' => Client::query()
                ->where('status', 1)
                ->whereHas('tags', function ($q) use ($value): void {
                    $q->where('client_tags.id', (int) $value)
                        ->orWhere('client_tags.slug', (string) $value)
                        ->orWhere('client_tags.name', (string) $value);
                })
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            default => throw new InvalidArgumentException('动态分群规则字段不支持。'),
        };
    }

    private function aggregateIds(string $model, string $foreignKey, string $column, string $operator, mixed $value, ?callable $scope = null): array
    {
        $aggregate = $model::query()->select($foreignKey);
        if ($scope !== null) {
            $scope($aggregate);
        }

        return $aggregate
            ->groupBy($foreignKey)
            ->havingRaw("SUM({$column}) {$operator} ?", [$value])
            ->pluck($foreignKey)
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function countIds(string $model, string $foreignKey, string $operator, int $value, ?callable $scope = null): array
    {
        $counter = $model::query()->select($foreignKey);
        if ($scope !== null) {
            $scope($counter);
        }

        return $counter
            ->groupBy($foreignKey)
            ->havingRaw("COUNT(*) {$operator} ?", [$value])
            ->pluck($foreignKey)
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function dateOperator(string $operator): string
    {
        return match ($operator) {
            '>', '>=' => '<=',
            '<', '<=' => '>=',
            default => '<=',
        };
    }

    private function refreshCount(ClientSegment $segment): int
    {
        $count = $segment->members()->count();
        $segment->update([
            'clients_count' => $count,
            'last_calculated_at' => now(),
        ]);

        return $count;
    }

    private function ensureStatic(ClientSegment $segment): void
    {
        if ($segment->isDynamic()) {
            throw new InvalidArgumentException('动态分群不能手动维护成员。');
        }
    }

    private function validClientIds(array $clientIds): array
    {
        return Client::query()
            ->whereIn('id', collect($clientIds)->map(fn ($id): int => (int) $id)->filter()->unique()->all())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function supportedFields(): array
    {
        return ['credit_balance', 'registered_days', 'total_spent', 'order_count', 'active_hosts_count', 'has_tag'];
    }
}
