<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailCampaign;
use App\Modules\User\Models\ClientGroup;
use App\Services\AdminAuditService;
use App\Services\EmailCampaignService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmailCampaignController extends Controller
{
    public function index(Request $request): View
    {
        $status = $this->queryString($request, 'status');
        $campaigns = EmailCampaign::query()
            ->when($status, fn ($query, string $status) => $query->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.email-campaigns.index', compact('campaigns', 'status'));
    }

    public function create(): View
    {
        return view('admin.email-campaigns.create', [
            'campaign' => new EmailCampaign(),
            'groups' => ClientGroup::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, EmailCampaignService $campaigns, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $campaign = $campaigns->create($data);

        $audit->record($request, 'email_campaign.create', $campaign, 'success', [
            'campaign_id' => $campaign->id,
            'total_recipients' => $campaign->total_recipients,
        ]);

        return redirect()->route('admin.campaigns.show', $campaign)->with('status', '邮件活动已创建');
    }

    public function show(EmailCampaign $campaign): View
    {
        $campaign->load(['recipients.client.group']);

        return view('admin.email-campaigns.show', compact('campaign'));
    }

    public function send(Request $request, EmailCampaign $campaign, EmailCampaignService $campaigns, AdminAuditService $audit): RedirectResponse
    {
        $campaigns->send($campaign);
        $audit->record($request, 'email_campaign.send', $campaign, 'success', [
            'campaign_id' => $campaign->id,
        ]);

        return redirect()->route('admin.campaigns.show', $campaign)->with('status', '邮件活动已开始发送');
    }

    public function schedule(Request $request, EmailCampaign $campaign, EmailCampaignService $campaigns, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
        ]);

        $success = $campaigns->schedule($campaign, Carbon::parse($data['scheduled_at']));
        $audit->record($request, 'email_campaign.schedule', $campaign, $success ? 'success' : 'failed', [
            'campaign_id' => $campaign->id,
            'scheduled_at' => $data['scheduled_at'],
        ]);

        if (!$success) {
            return redirect()->route('admin.campaigns.show', $campaign)->with('error', '当前活动状态不能安排发送');
        }

        return redirect()->route('admin.campaigns.show', $campaign)->with('status', '邮件活动已安排发送');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'target_groups' => ['nullable', 'array'],
            'target_groups.*' => ['integer', Rule::exists('client_groups', 'id')],
        ]);
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
