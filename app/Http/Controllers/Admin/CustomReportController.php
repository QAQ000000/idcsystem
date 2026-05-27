<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\CustomReport;
use App\Modules\Admin\Services\CustomReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CustomReportController extends Controller
{
    public function index(): View
    {
        $reports = CustomReport::query()
            ->with('creator')
            ->withCount('executions')
            ->latest()
            ->paginate(20);

        return view('admin.reports.custom.index', compact('reports'));
    }

    public function create(): View
    {
        return view('admin.reports.custom.create');
    }

    public function store(Request $request, CustomReportService $reports): RedirectResponse
    {
        $data = $this->validated($request);
        try {
            $reports->assertSafeSql($data['query']);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['query' => $exception->getMessage()]);
        }
        $columns = $this->columns($data['columns'] ?? '');
        $recipients = $this->recipients($data['recipients'] ?? '');
        unset($data['columns'], $data['recipients']);

        $report = CustomReport::query()->create(array_merge($data, [
            'type' => 'sql',
            'created_by' => $request->user('admin')->id,
            'columns' => $columns,
            'recipients' => $recipients,
        ]));

        return redirect()
            ->route('admin.reports.custom.show', $report)
            ->with('status', '自定义报表已创建');
    }

    public function show(CustomReport $customReport, CustomReportService $reports): View
    {
        $customReport->load(['creator', 'executions' => fn ($query) => $query->latest('executed_at')->limit(10)]);
        $result = null;
        $error = null;

        try {
            $result = $reports->execute($customReport);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        return view('admin.reports.custom.show', compact('customReport', 'result', 'error'));
    }

    public function export(CustomReport $customReport, CustomReportService $reports): StreamedResponse
    {
        return $reports->exportCsv($customReport);
    }

    public function destroy(CustomReport $customReport): RedirectResponse
    {
        $customReport->delete();

        return redirect()->route('admin.reports.custom.index')->with('status', '自定义报表已删除');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'query' => ['required', 'string', 'max:10000'],
            'columns' => ['nullable', 'string', 'max:1000'],
            'schedule' => ['nullable', 'string', 'in:every_minute,hourly,daily,* * * * *,0 * * * *,0 8 * * *'],
            'recipients' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function columns(string $input): ?array
    {
        $columns = $this->splitList($input);

        return $columns === [] ? null : $columns;
    }

    private function recipients(string $input): ?array
    {
        $recipients = array_values(array_filter($this->splitList($input), fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL)));

        return $recipients === [] ? null : $recipients;
    }

    private function splitList(string $input): array
    {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $input) ?: []))));
    }
}
