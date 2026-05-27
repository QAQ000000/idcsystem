<?php

namespace App\Services;

use App\Jobs\SendCampaignEmailJob;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Modules\User\Models\Client;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class EmailCampaignService
{
    public function create(array $data): EmailCampaign
    {
        return DB::transaction(function () use ($data) {
            $groupIds = $this->normalizeGroups($data['target_groups'] ?? []);
            $segmentIds = $this->normalizeIds($data['target_segments'] ?? []);
            $campaign = EmailCampaign::query()->create([
                'name' => $data['name'],
                'subject' => $data['subject'],
                'content' => $data['content'],
                'target_groups' => $groupIds === [] ? null : $groupIds,
                'target_segments' => $segmentIds === [] ? null : $segmentIds,
                'status' => 'draft',
            ]);

            $this->syncRecipients($campaign);

            return $campaign->fresh(['recipients.client']);
        });
    }

    public function schedule(EmailCampaign $campaign, Carbon $scheduledAt): bool
    {
        if (!in_array($campaign->status, ['draft', 'scheduled'], true)) {
            return false;
        }

        return $campaign->update([
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
        ]);
    }

    public function send(EmailCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            $lockedCampaign = EmailCampaign::query()->whereKey($campaign->id)->lockForUpdate()->first();
            if (!$lockedCampaign || !in_array($lockedCampaign->status, ['draft', 'scheduled'], true)) {
                return;
            }

            if ((int) $lockedCampaign->total_recipients === 0) {
                $this->syncRecipients($lockedCampaign);
                $lockedCampaign->refresh();
            }

            $lockedCampaign->update(['status' => 'sending']);

            $recipientIds = $lockedCampaign->recipients()
                ->where('status', 'pending')
                ->pluck('id')
                ->all();

            if ($recipientIds === []) {
                $lockedCampaign->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                return;
            }

            DB::afterCommit(function () use ($recipientIds): void {
                foreach ($recipientIds as $recipientId) {
                    SendCampaignEmailJob::dispatch((int) $recipientId);
                }
            });
        });
    }

    public function sendScheduled(): int
    {
        $count = 0;
        EmailCampaign::query()
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->chunkById(50, function ($campaigns) use (&$count): void {
                foreach ($campaigns as $campaign) {
                    $this->send($campaign);
                    $count++;
                }
            });

        return $count;
    }

    public function trackOpen(EmailCampaignRecipient $recipient): void
    {
        if ($recipient->opened_at !== null) {
            return;
        }

        DB::transaction(function () use ($recipient): void {
            $updated = EmailCampaignRecipient::query()
                ->whereKey($recipient->id)
                ->whereNull('opened_at')
                ->update(['opened_at' => now()]);

            if ($updated === 1) {
                EmailCampaign::query()->whereKey($recipient->campaign_id)->increment('opened_count');
            }
        });
    }

    public function trackClick(EmailCampaignRecipient $recipient): void
    {
        if ($recipient->clicked_at !== null) {
            return;
        }

        DB::transaction(function () use ($recipient): void {
            $updated = EmailCampaignRecipient::query()
                ->whereKey($recipient->id)
                ->whereNull('clicked_at')
                ->update(['clicked_at' => now()]);

            if ($updated === 1) {
                EmailCampaign::query()->whereKey($recipient->campaign_id)->increment('clicked_count');
            }
        });
    }

    public function renderForRecipient(EmailCampaignRecipient $recipient): string
    {
        $recipient->loadMissing(['campaign', 'client']);
        $content = app(MailService::class)->render($recipient->campaign->content, [
            'client_name' => $recipient->client?->username ?? '',
            'client_email' => $recipient->client?->email ?? '',
            'campaign_name' => $recipient->campaign->name,
        ]);

        $content = $this->rewriteLinks($recipient, $content);
        $pixel = '<img src="' . e(URL::temporarySignedRoute('campaign.track.open', now()->addDays(30), $recipient)) . '" width="1" height="1" alt="" style="display:none" />';

        return $content . "\n" . $pixel;
    }

    public function markRecipientSent(EmailCampaignRecipient $recipient): void
    {
        DB::transaction(function () use ($recipient): void {
            $updated = EmailCampaignRecipient::query()
                ->whereKey($recipient->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

            if ($updated !== 1) {
                return;
            }

            $campaign = EmailCampaign::query()->whereKey($recipient->campaign_id)->lockForUpdate()->first();
            if (!$campaign) {
                return;
            }

            $campaign->increment('sent_count');
            $campaign->refresh();
            if ($campaign->sent_count >= $campaign->total_recipients
                && $campaign->recipients()->where('status', 'pending')->doesntExist()) {
                $campaign->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            }
        });
    }

    public function markRecipientFailed(EmailCampaignRecipient $recipient): void
    {
        $recipient->update(['status' => 'failed']);
    }

    private function syncRecipients(EmailCampaign $campaign): void
    {
        $clients = Client::query()
            ->where('status', 1)
            ->whereNotNull('email')
            ->when($this->hasTargets($campaign), function ($query) use ($campaign): void {
                $query->where(function ($query) use ($campaign): void {
                    if ($campaign->target_groups !== null && $campaign->target_groups !== []) {
                        $query->whereIn('group_id', $campaign->target_groups);
                    }

                    if ($campaign->target_segments !== null && $campaign->target_segments !== []) {
                        $method = ($campaign->target_groups !== null && $campaign->target_groups !== []) ? 'orWhereHas' : 'whereHas';
                        $query->{$method}('segments', fn ($query) => $query->whereIn('client_segments.id', $campaign->target_segments));
                    }
                });
            })
            ->get(['id']);

        foreach ($clients as $client) {
            EmailCampaignRecipient::query()->firstOrCreate([
                'campaign_id' => $campaign->id,
                'client_id' => $client->id,
            ]);
        }

        $campaign->update([
            'total_recipients' => $campaign->recipients()->count(),
        ]);
    }

    private function normalizeGroups(mixed $groups): array
    {
        return $this->normalizeIds($groups);
    }

    private function normalizeIds(mixed $ids): array
    {
        return collect(is_array($ids) ? $ids : [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function hasTargets(EmailCampaign $campaign): bool
    {
        return ($campaign->target_groups !== null && $campaign->target_groups !== [])
            || ($campaign->target_segments !== null && $campaign->target_segments !== []);
    }

    private function rewriteLinks(EmailCampaignRecipient $recipient, string $content): string
    {
        return preg_replace_callback('/href=(["\'])(https?:\/\/[^"\']+)\1/i', function (array $matches) use ($recipient): string {
            $tracked = URL::temporarySignedRoute('campaign.track.click', now()->addDays(30), [
                'recipient' => $recipient->id,
                'linkId' => substr(sha1($matches[2]), 0, 12),
                'url' => $matches[2],
            ]);

            return 'href=' . $matches[1] . e($tracked) . $matches[1];
        }, $content) ?? $content;
    }
}
