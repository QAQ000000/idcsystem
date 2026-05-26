<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\FinancialStatement;
use App\Modules\Finance\Services\FinancialStatementService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FinancialStatementController extends Controller
{
    public function index(): View
    {
        $statements = FinancialStatement::query()
            ->latest('period_end')
            ->paginate(20);

        return view('admin.financial-statements.index', compact('statements'));
    }

    public function generate(Request $request, FinancialStatementService $statements): RedirectResponse
    {
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        $statement = $statements->generate(
            Carbon::parse($data['period_start']),
            Carbon::parse($data['period_end'])
        );

        return redirect()
            ->route('admin.financial-statements.show', $statement)
            ->with('status', '财务对账报表已生成');
    }

    public function show(FinancialStatement $statement): View
    {
        return view('admin.financial-statements.show', compact('statement'));
    }

    public function export(FinancialStatement $statement, FinancialStatementService $statements): BinaryFileResponse
    {
        $path = $statements->exportToExcel($statement);

        return response()->download($path, basename($path), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
