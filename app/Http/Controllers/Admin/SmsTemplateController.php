<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SmsTemplate;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;

class SmsTemplateController extends Controller
{
    public function index()
    {
        return view('admin.sms-templates.index', [
            'templates' => SmsTemplate::query()->orderBy('name')->paginate(20),
        ]);
    }

    public function edit(SmsTemplate $smsTemplate)
    {
        return view('admin.sms-templates.edit', compact('smsTemplate'));
    }

    public function update(Request $request, SmsTemplate $smsTemplate, AdminAuditService $audit)
    {
        $data = $request->validate([
            'content' => ['required', 'string'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $smsTemplate->update([
            'content' => $data['content'],
            'enabled' => $request->boolean('enabled'),
        ]);
        $audit->record($request, 'sms_template.update', $smsTemplate, 'success', [
            'enabled' => $request->boolean('enabled'),
        ]);

        return redirect()->route('admin.sms-templates.index')->with('status', '短信模板已保存');
    }
}
