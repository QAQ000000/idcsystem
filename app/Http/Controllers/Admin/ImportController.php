<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessImportJob;
use App\Models\ImportJob;
use App\Services\ImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function index(): View
    {
        $jobs = ImportJob::query()
            ->with('adminUser')
            ->latest()
            ->paginate(20);

        return view('admin.imports.index', compact('jobs'));
    }

    public function create(string $type): View
    {
        abort_unless(in_array($type, ImportService::TYPES, true), 404);

        return view('admin.imports.create', ['type' => $type]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        abort_unless(in_array($type, ImportService::TYPES, true), 404);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $path = $data['file']->store('imports', 'local');
        $job = ImportJob::query()->create([
            'admin_user_id' => auth('admin')->id(),
            'type' => $type,
            'file_path' => $path,
            'status' => 'pending',
        ]);

        ProcessImportJob::dispatch($job->id);

        return redirect()
            ->route('admin.imports.show', $job)
            ->with('status', '导入任务已创建');
    }

    public function show(ImportJob $importJob): View
    {
        $importJob->load('adminUser');

        return view('admin.imports.show', compact('importJob'));
    }

    public function template(string $type): StreamedResponse
    {
        abort_unless(in_array($type, ImportService::TYPES, true), 404);
        $rows = ImportService::template($type);

        return response()->streamDownload(function () use ($rows): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $type . '_template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
