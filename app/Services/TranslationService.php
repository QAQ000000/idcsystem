<?php

namespace App\Services;

use App\Models\Translation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class TranslationService
{
    public function importFromFiles(?array $locales = null): int
    {
        $files = 0;
        foreach ($locales ?? config('app.available_locales', ['zh_CN', 'en']) as $locale) {
            $path = lang_path($locale);
            if (!File::isDirectory($path)) {
                continue;
            }

            foreach (File::files($path) as $file) {
                $translations = require $file->getPathname();
                if (!is_array($translations)) {
                    continue;
                }

                $this->importGroup($locale, pathinfo($file->getFilename(), PATHINFO_FILENAME), $translations);
                $files++;
            }
        }

        return $files;
    }

    public function exportToFiles(?array $locales = null): int
    {
        $query = Translation::query()
            ->when($locales !== null, fn ($query) => $query->whereIn('locale', $locales));
        $groups = $query->select('locale', 'group')->distinct()->orderBy('locale')->orderBy('group')->get();
        $files = 0;

        foreach ($groups as $item) {
            $translations = Translation::query()
                ->where('locale', $item->locale)
                ->where('group', $item->group)
                ->orderBy('key')
                ->get();
            $path = lang_path("{$item->locale}/{$item->group}.php");
            File::ensureDirectoryExists(dirname($path));
            File::put($path, "<?php\n\nreturn " . var_export($this->buildArray($translations), true) . ";\n");
            $files++;
        }

        return $files;
    }

    public function autoTranslate(string $fromLocale, string $toLocale): int
    {
        $this->ensureLocale($fromLocale);
        $this->ensureLocale($toLocale);

        $count = 0;
        Translation::query()
            ->where('locale', $fromLocale)
            ->where('is_translated', true)
            ->orderBy('id')
            ->chunkById(100, function ($translations) use ($toLocale, $fromLocale, &$count): void {
                foreach ($translations as $translation) {
                    $existing = Translation::query()
                        ->where('locale', $toLocale)
                        ->where('group', $translation->group)
                        ->where('key', $translation->key)
                        ->first();

                    if ($existing?->is_translated) {
                        continue;
                    }

                    Translation::query()->updateOrCreate(
                        [
                            'locale' => $toLocale,
                            'group' => $translation->group,
                            'key' => $translation->key,
                        ],
                        [
                            'value' => $this->callTranslationApi((string) $translation->value, $fromLocale, $toLocale),
                            'is_translated' => true,
                            'translated_by' => $this->translationProviderConfigured() ? 'auto' : 'fallback',
                        ]
                    );
                    $count++;
                }
            });

        return $count;
    }

    private function importGroup(string $locale, string $group, array $translations, string $prefix = ''): void
    {
        foreach ($translations as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            if (is_array($value)) {
                $this->importGroup($locale, $group, $value, $fullKey);
                continue;
            }

            Translation::query()->updateOrCreate(
                [
                    'locale' => $locale,
                    'group' => $group,
                    'key' => $fullKey,
                ],
                [
                    'value' => (string) $value,
                    'is_translated' => true,
                    'translated_by' => 'manual',
                ]
            );
        }
    }

    private function buildArray($translations): array
    {
        $result = [];
        foreach ($translations as $translation) {
            data_set($result, $translation->key, $translation->value);
        }

        return $result;
    }

    private function callTranslationApi(string $text, string $from, string $to): string
    {
        if (!$this->translationProviderConfigured()) {
            return $text;
        }

        $appId = (string) config('services.baidu_translate.app_id');
        $key = (string) config('services.baidu_translate.key');
        $salt = (string) time();
        $response = Http::timeout(5)->get('https://fanyi-api.baidu.com/api/trans/vip/translate', [
            'q' => $text,
            'from' => $this->mapLocale($from),
            'to' => $this->mapLocale($to),
            'appid' => $appId,
            'salt' => $salt,
            'sign' => md5($appId . $text . $salt . $key),
        ]);

        return (string) ($response->json('trans_result.0.dst') ?? $text);
    }

    private function translationProviderConfigured(): bool
    {
        return filled(config('services.baidu_translate.app_id')) && filled(config('services.baidu_translate.key'));
    }

    private function mapLocale(string $locale): string
    {
        return match ($locale) {
            'zh_CN' => 'zh',
            'ja' => 'jp',
            'ko' => 'kor',
            'es' => 'spa',
            'fr' => 'fra',
            'pt_BR' => 'pt',
            'vi' => 'vie',
            default => str_replace('_', '-', $locale),
        };
    }

    private function ensureLocale(string $locale): void
    {
        if (!in_array($locale, config('app.available_locales', ['zh_CN', 'en']), true)) {
            throw new InvalidArgumentException('Unsupported locale.');
        }
    }
}
