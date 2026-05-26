<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function index(Request $request)
    {
        $type = $this->queryString($request, 'type');
        $backups = Backup::query()
            ->when($type, fn ($query, string $value) => $query->where('type', $value))
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.backups.index', [
            'backups' => $backups,
            'type' => $type,
            'types' => ['database', 'files'],
        ]);
    }

    public function database(BackupService $backups)
    {
        $backup = $backups->backupDatabase();

        return redirect()->route('admin.backups.index')
            ->with($backup->status === 'completed' ? 'status' : 'error', $backup->status === 'completed' ? '数据库备份已完成。' : $backup->error_message);
    }

    public function files(BackupService $backups)
    {
        $backup = $backups->backupFiles();

        return redirect()->route('admin.backups.index')
            ->with($backup->status === 'completed' ? 'status' : 'error', $backup->status === 'completed' ? '文件备份已完成。' : $backup->error_message);
    }

    public function download(Backup $backup): BinaryFileResponse
    {
        abort_unless(is_file($backup->file_path), 404);

        return response()->download($backup->file_path);
    }

    public function restore(Backup $backup, BackupService $backups)
    {
        try {
            $backups->restore($backup);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.backups.index')->with('status', '数据库备份恢复已执行。');
    }

    public function destroy(Backup $backup, BackupService $backups)
    {
        $backups->delete($backup);

        return redirect()->route('admin.backups.index')->with('status', '备份已删除。');
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
