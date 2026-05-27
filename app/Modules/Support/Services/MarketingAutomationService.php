<?php

namespace App\Modules\Support\Services;

use App\Jobs\ProcessAutomationStepJob;
use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use App\Modules\Support\Models\MarketingAutomation;
use App\Modules\Support\Models\MarketingAutomationExecution;
use App\Modules\Support\Models\MarketingAutomationLog;
use App\Modules\User\Models\Client;
use App\Modules\User\Models\ClientSegment;
use App\Modules\User\Models\ClientTag;
use App\Modules\User\Services\ClientSegmentService;
use App\Modules\User\Services\ClientTagService;
use App\Services\MailService;
use App\Services\SmsService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class MarketingAutomationService
{
    private const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'contains'];

    public function trigger(string $event, array $data): int
    {
        $clientId = (int) ($data['client_id'] ?? 0);
        if ($clientId <= 0 || !Client::query()->whereKey($clientId)->exists()) {
            return 0;
        }

        $started = 0;
        MarketingAutomation::query()
            ->where('trigger_event', $event)
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(50, function ($automations) use ($data, $clientId, &$started): void {
                foreach ($automations as $automation) {
                    if (!$this->matchesConditions($automation, $data)) {
                        continue;
                    }

                    $this->startExecution($automation, $clientId, $data);
                    $started++;
                }
            });

        return $started;
    }

    public function startExecution(MarketingAutomation $automation, int $clientId, array $context = []): MarketingAutomationExecution
    {
        return DB::transaction(function () use ($automation, $clientId, $context): MarketingAutomationExecution {
            $execution = MarketingAutomationExecution::query()->create([
                'automation_id' => $automation->id,
                'client_id' => $clientId,
                'current_step' => 0,
                'status' => 'running',
                'context' => $context,
                'started_at' => now(),
            ]);

            $automation->increment('executions_count');
            $this->executeNextStep($execution->fresh(['automation', 'client']));

            return $execution->fresh();
        });
    }

    public function processDueExecutions(): int
    {
        $count = 0;
        MarketingAutomationExecution::query()
            ->where('status', 'running')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->chunkById(100, function ($executions) use (&$count): void {
                foreach ($executions as $execution) {
                    $this->executeNextStep($execution);
                    $count++;
                }
            });

        return $count;
    }

    public function executeNextStep(MarketingAutomationExecution $execution): void
    {
        $execution = $execution->fresh(['automation', 'client']);
        if (!$execution || $execution->status !== 'running') {
            return;
        }

        $steps = $execution->automation->steps ?? [];
        while ($execution->current_step < count($steps)) {
            $stepIndex = $execution->current_step;
            $step = $this->selectVariant($steps[$stepIndex], $execution);
            $action = (string) ($step['action'] ?? '');

            try {
                $result = $this->executeStep($execution, $step);
                $this->log($execution, $stepIndex, $action, $result['status'], $result['message']);

                if ($action === 'wait') {
                    $execution->update([
                        'current_step' => $stepIndex + 1,
                        'next_run_at' => $result['next_run_at'],
                    ]);
                    ProcessAutomationStepJob::dispatch($execution->id)
                        ->delay($result['next_run_at'])
                        ->afterCommit();

                    return;
                }

                if ($result['status'] === 'failed') {
                    $execution->update([
                        'status' => 'failed',
                        'next_run_at' => null,
                        'completed_at' => now(),
                    ]);

                    return;
                }

                $execution->update([
                    'current_step' => $stepIndex + 1,
                    'next_run_at' => null,
                ]);
                $execution = $execution->fresh(['automation', 'client']);
            } catch (Throwable $exception) {
                $this->log($execution, $stepIndex, $action ?: 'unknown', 'failed', $exception->getMessage());
                $execution->update([
                    'status' => 'failed',
                    'next_run_at' => null,
                    'completed_at' => now(),
                ]);

                return;
            }
        }

        $execution->update([
            'status' => 'completed',
            'next_run_at' => null,
            'completed_at' => now(),
        ]);
    }

    public function matchesConditions(MarketingAutomation $automation, array $data): bool
    {
        $conditions = $automation->trigger_conditions ?? [];
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                return false;
            }

            $operator = (string) ($condition['operator'] ?? '=');
            if (!in_array($operator, self::OPERATORS, true)) {
                return false;
            }

            if (!$this->evaluateCondition(
                data_get($data, (string) ($condition['field'] ?? '')),
                $operator,
                $condition['value'] ?? null
            )) {
                return false;
            }
        }

        return true;
    }

    private function executeStep(MarketingAutomationExecution $execution, array $step): array
    {
        return match ((string) ($step['action'] ?? '')) {
            'send_email' => $this->sendEmail($execution, $step),
            'send_sms' => $this->sendSms($execution, $step),
            'add_tag' => $this->addTag($execution, $step),
            'remove_tag' => $this->removeTag($execution, $step),
            'add_to_segment' => $this->addToSegment($execution, $step),
            'wait' => $this->wait($step),
            default => throw new InvalidArgumentException('Unsupported automation action'),
        };
    }

    private function sendEmail(MarketingAutomationExecution $execution, array $step): array
    {
        $template = $this->templateName($step, EmailTemplate::class);
        $success = app(MailService::class)->sendTemplate(
            $template,
            (string) $execution->client->email,
            $this->variables($execution, $step),
            ['async' => false]
        );

        return [
            'status' => $success ? 'success' : 'failed',
            'message' => $success ? "Email template {$template} sent" : "Email template {$template} failed",
        ];
    }

    private function sendSms(MarketingAutomationExecution $execution, array $step): array
    {
        if (!$execution->client->phone) {
            return ['status' => 'skipped', 'message' => 'Client phone is empty'];
        }

        $template = $this->templateName($step, SmsTemplate::class);
        $success = app(SmsService::class)->send(
            (string) $execution->client->phone,
            $template,
            $this->variables($execution, $step),
            ['async' => false]
        );

        return [
            'status' => $success ? 'success' : 'failed',
            'message' => $success ? "SMS template {$template} sent" : "SMS template {$template} failed",
        ];
    }

    private function addTag(MarketingAutomationExecution $execution, array $step): array
    {
        $tag = $this->tag($step);
        app(ClientTagService::class)->attachTag($execution->client, $tag);

        return ['status' => 'success', 'message' => "Tag {$tag->slug} added"];
    }

    private function removeTag(MarketingAutomationExecution $execution, array $step): array
    {
        $tag = $this->tag($step);
        app(ClientTagService::class)->detachTag($execution->client, $tag);

        return ['status' => 'success', 'message' => "Tag {$tag->slug} removed"];
    }

    private function addToSegment(MarketingAutomationExecution $execution, array $step): array
    {
        $segment = $this->segment($step);
        app(ClientSegmentService::class)->addToSegment($segment, [$execution->client_id]);

        return ['status' => 'success', 'message' => "Segment {$segment->id} added"];
    }

    private function wait(array $step): array
    {
        $minutes = max(1, (int) ($step['minutes'] ?? $step['delay_minutes'] ?? 0));

        return [
            'status' => 'success',
            'message' => "Waiting {$minutes} minutes",
            'next_run_at' => now()->addMinutes($minutes),
        ];
    }

    private function evaluateCondition(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=' => (string) $actual === (string) $expected,
            '!=' => (string) $actual !== (string) $expected,
            '>' => (float) $actual > (float) $expected,
            '>=' => (float) $actual >= (float) $expected,
            '<' => (float) $actual < (float) $expected,
            '<=' => (float) $actual <= (float) $expected,
            'contains' => str_contains((string) $actual, (string) $expected),
            default => false,
        };
    }

    private function selectVariant(array $step, MarketingAutomationExecution $execution): array
    {
        $variants = $step['variants'] ?? null;
        if (!is_array($variants) || $variants === []) {
            return $step;
        }

        $variant = $variants[$execution->client_id % count($variants)] ?? $variants[0];

        return is_array($variant) ? array_merge($step, $variant, ['variants' => null]) : $step;
    }

    private function templateName(array $step, string $templateModel): string
    {
        if (!empty($step['template'])) {
            return (string) $step['template'];
        }

        if (!empty($step['template_id'])) {
            $template = $templateModel::query()->find((int) $step['template_id']);
            if ($template) {
                return (string) $template->name;
            }
        }

        throw new InvalidArgumentException('Automation template is required');
    }

    private function variables(MarketingAutomationExecution $execution, array $step): array
    {
        return array_merge(
            $execution->context ?? [],
            [
                'client_id' => $execution->client_id,
                'client_name' => $execution->client->username,
                'email' => $execution->client->email,
            ],
            is_array($step['variables'] ?? null) ? $step['variables'] : []
        );
    }

    private function tag(array $step): ClientTag
    {
        $value = $step['tag_id'] ?? $step['tag'] ?? $step['tag_slug'] ?? null;

        return ClientTag::query()
            ->where('id', is_numeric($value) ? (int) $value : 0)
            ->orWhere('slug', (string) $value)
            ->orWhere('name', (string) $value)
            ->firstOrFail();
    }

    private function segment(array $step): ClientSegment
    {
        $value = $step['segment_id'] ?? $step['segment'] ?? null;

        return ClientSegment::query()
            ->where('id', is_numeric($value) ? (int) $value : 0)
            ->orWhere('name', (string) $value)
            ->firstOrFail();
    }

    private function log(
        MarketingAutomationExecution $execution,
        int $stepIndex,
        string $action,
        string $status,
        ?string $message = null
    ): void {
        MarketingAutomationLog::query()->create([
            'execution_id' => $execution->id,
            'step_index' => $stepIndex,
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'executed_at' => now(),
        ]);
    }
}
