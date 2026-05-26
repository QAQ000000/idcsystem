<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaignRecipient;
use App\Services\EmailCampaignService;
use Illuminate\Http\Request;

class CampaignTrackingController extends Controller
{
    public function open(EmailCampaignRecipient $recipient, EmailCampaignService $campaigns)
    {
        $campaigns->trackOpen($recipient);

        $pixel = base64_decode('R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');

        return response($pixel, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, EmailCampaignRecipient $recipient, string $linkId, EmailCampaignService $campaigns)
    {
        $campaigns->trackClick($recipient);

        $url = (string) $request->query('url', '');
        if (!preg_match('/^https?:\/\//i', $url)) {
            abort(404);
        }

        return redirect()->away($url);
    }
}
