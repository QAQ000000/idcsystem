<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('admin.announcements.index', [
            'announcements' => Announcement::query()->latest()->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.announcements.create', [
            'announcement' => new Announcement(['type' => 'info', 'active' => true]),
        ]);
    }

    public function store(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $announcement = Announcement::query()->create($data);
        $audit->record($request, 'announcement.create', $announcement, 'success', $data);

        return redirect()->route('admin.announcements.index')->with('status', '公告已创建');
    }

    public function edit(Announcement $announcement): View
    {
        return view('admin.announcements.edit', compact('announcement'));
    }

    public function update(Request $request, Announcement $announcement, AdminAuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $announcement->update($data);
        $audit->record($request, 'announcement.update', $announcement, 'success', $data);

        return redirect()->route('admin.announcements.index')->with('status', '公告已保存');
    }

    public function destroy(Request $request, Announcement $announcement, AdminAuditService $audit): RedirectResponse
    {
        $announcementId = $announcement->id;
        $announcement->delete();
        $audit->record($request, 'announcement.delete', null, 'success', [
            'announcement_id' => $announcementId,
        ]);

        return redirect()->route('admin.announcements.index')->with('status', '公告已删除');
    }

    public function toggle(Request $request, Announcement $announcement, AdminAuditService $audit): RedirectResponse
    {
        $announcement->update(['active' => !$announcement->active]);
        $audit->record($request, 'announcement.toggle', $announcement, 'success', [
            'active' => $announcement->active,
        ]);

        return redirect()->route('admin.announcements.index')->with('status', $announcement->active ? '公告已启用' : '公告已停用');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'content' => ['required', 'string', 'max:5000'],
            'type' => ['required', Rule::in(['info', 'warning', 'maintenance'])],
            'active' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $data['active'] = $request->boolean('active');

        return $data;
    }
}
