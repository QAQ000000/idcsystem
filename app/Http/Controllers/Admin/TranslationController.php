<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use App\Services\AdminAuditService;
use App\Services\TranslationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TranslationController extends Controller
{
    public function index(Request $request): View
    {
        $locale = (string) $request->input('locale', config('app.locale'));
        $group = (string) $request->input('group', '');
        $search = trim((string) $request->input('search', ''));

        return view('admin.translations.index', [
            'translations' => Translation::query()
                ->where('locale', $locale)
                ->when($group !== '', fn ($query) => $query->where('group', $group))
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($query) use ($search): void {
                        $query->where('key', 'like', "%{$search}%")
                            ->orWhere('value', 'like', "%{$search}%");
                    });
                })
                ->orderBy('group')
                ->orderBy('key')
                ->paginate(50)
                ->withQueryString(),
            'locales' => config('app.available_locales', ['zh_CN', 'en']),
            'groups' => Translation::query()->select('group')->distinct()->orderBy('group')->pluck('group'),
            'currentLocale' => $locale,
            'currentGroup' => $group,
            'search' => $search,
        ]);
    }

    public function update(Request $request, Translation $translation, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'value' => ['required', 'string'],
        ]);

        $translation->update([
            'value' => $data['value'],
            'is_translated' => true,
            'translated_by' => 'manual',
        ]);
        $audit->record($request, 'translation.update', $translation, 'success', [
            'locale' => $translation->locale,
            'group' => $translation->group,
            'key' => $translation->key,
        ]);

        return back()->with('status', '翻译已更新');
    }

    public function import(TranslationService $translations, AdminAuditService $audit, Request $request): RedirectResponse
    {
        $count = $translations->importFromFiles();
        $audit->record($request, 'translation.import', null, 'success', ['files' => $count]);

        return back()->with('status', "已导入 {$count} 个翻译文件");
    }

    public function export(TranslationService $translations, AdminAuditService $audit, Request $request): RedirectResponse
    {
        $count = $translations->exportToFiles();
        $audit->record($request, 'translation.export', null, 'success', ['files' => $count]);

        return back()->with('status', "已导出 {$count} 个翻译文件");
    }

    public function autoTranslate(Request $request, TranslationService $translations, AdminAuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'from' => ['required', 'string', Rule::in(config('app.available_locales', ['zh_CN', 'en']))],
            'to' => ['required', 'string', Rule::in(config('app.available_locales', ['zh_CN', 'en'])), 'different:from'],
        ]);

        $count = $translations->autoTranslate($data['from'], $data['to']);
        $audit->record($request, 'translation.auto_translate', null, 'success', $data + ['count' => $count]);

        return back()->with('status', "已自动翻译 {$count} 条记录");
    }
}
