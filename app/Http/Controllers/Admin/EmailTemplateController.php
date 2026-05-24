<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailTemplate;
use App\Services\AdminAuditService;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    public function index()
    {
        return view('admin.email-templates.index', [
            'templates' => EmailTemplate::query()->orderBy('name')->paginate(20),
        ]);
    }

    public function edit(EmailTemplate $emailTemplate)
    {
        return view('admin.email-templates.edit', compact('emailTemplate'));
    }

    public function update(Request $request, EmailTemplate $emailTemplate, AdminAuditService $audit)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $emailTemplate->update([
            'subject' => $data['subject'],
            'body' => $data['body'],
            'enabled' => $request->boolean('enabled'),
        ]);
        $audit->record($request, 'email_template.update', $emailTemplate, 'success', [
            'subject' => $data['subject'],
            'enabled' => $request->boolean('enabled'),
        ]);

        return redirect()->route('admin.email-templates.index')->with('status', '邮件模板已保存');
    }
}
