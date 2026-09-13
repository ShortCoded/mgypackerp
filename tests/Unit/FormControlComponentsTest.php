<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\SettingService;
use Tests\TestCase;

uses(TestCase::class);

/**
 * @return list<string>
 */
function sharedFormControlOpeningTags(string $contents): array
{
    $tags = [];
    $offset = 0;
    $length = strlen($contents);

    while (($start = strpos($contents, '<x-forms.', $offset)) !== false) {
        $quote = null;
        $parenthesisDepth = 0;

        for ($index = $start; $index < $length; $index++) {
            $character = $contents[$index];

            if ($quote !== null) {
                if ($character === $quote && ($index === 0 || $contents[$index - 1] !== '\\')) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '(') {
                $parenthesisDepth++;

                continue;
            }

            if ($character === ')' && $parenthesisDepth > 0) {
                $parenthesisDepth--;

                continue;
            }

            if ($character === '>' && $parenthesisDepth === 0) {
                $tags[] = substr($contents, $start, $index - $start + 1);
                $offset = $index + 1;

                continue 2;
            }
        }

        break;
    }

    return $tags;
}

test('shared form controls preserve attributes and localized picker contracts', function (): void {
    app()->setLocale('ar');
    $settings = Mockery::mock(SettingService::class);
    $settings->shouldReceive('dateFormat')->andReturn('d/m/Y');
    $settings->shouldReceive('dateTimeFormat')->andReturn('d/m/Y H:i');
    app()->instance(DateFormatService::class, new DateFormatService($settings));

    $html = Blade::render(<<<'BLADE'
        <x-forms.input id="reference" name="reference" value="ABC" required data-source="screen" />
        <x-forms.date-input id="document_date" name="document_date" value="2026-09-13" required />
        <x-forms.date-input id="occurred_at" name="occurred_at" value="2026-09-13T14:30" enable-time />
        <x-forms.select id="supplier" name="supplier" variant="ajax" url="/select2/suppliers" placeholder="اختر المورد" required data-depends-on="#company">
            <option value="SUP-1" selected>المورد الأول</option>
        </x-forms.select>
        <x-forms.textarea id="notes" name="notes" rows="4">ملاحظة</x-forms.textarea>
    BLADE);

    expect($html)
        ->toContain('id="reference"')
        ->toContain('class="form-control"')
        ->toContain('data-source="screen"')
        ->toContain('id="document_date"')
        ->toContain('class="form-control js-date-picker"')
        ->toContain('value="2026-09-13"')
        ->toContain('data-storage-format="Y-m-d"')
        ->toContain('data-locale="ar"')
        ->toContain('id="occurred_at"')
        ->toContain('data-enable-time="true"')
        ->toContain('data-storage-format="Y-m-d H:i:S"')
        ->toContain('class="form-select js-select2-ajax"')
        ->toContain('data-url="/select2/suppliers"')
        ->toContain('data-placeholder="اختر المورد"')
        ->toContain('data-depends-on="#company"')
        ->toContain('<option value="SUP-1" selected>المورد الأول</option>')
        ->toContain('rows="4"')
        ->toContain('ملاحظة');
});

test('blade screens cannot bypass the shared date input component', function (): void {
    $violations = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->reject(fn (SplFileInfo $file): bool => $file->getRelativePathname() === 'components/forms/date-input.blade.php')
        ->filter(function (SplFileInfo $file): bool {
            $contents = File::get($file->getPathname());

            return preg_match('/<input\b[^>]*\btype\s*=\s*["\'](?:date|datetime-local)["\']/i', $contents) === 1
                || preg_match('/<input\b[^>]*\bjs-date-picker\b/i', $contents) === 1;
        })
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

test('blade screens render editable controls through shared form components', function (): void {
    $violations = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->reject(fn (SplFileInfo $file): bool => str_starts_with($file->getRelativePathname(), 'components/'))
        ->filter(fn (SplFileInfo $file): bool => preg_match('/<(?:input|select|textarea)\b/i', File::get($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBe([]);
});

test('shared form control calls use blade safe component syntax', function (): void {
    $violations = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->reject(fn (SplFileInfo $file): bool => str_starts_with($file->getRelativePathname(), 'components/'))
        ->flatMap(function (SplFileInfo $file): array {
            return collect(sharedFormControlOpeningTags(File::get($file->getPathname())))
                ->filter(function (string $tag): bool {
                    $hasUnsafeDirective = preg_match('/@(disabled|required|readonly|checked|if|unless|isset|json)\b/', $tag) === 1;
                    $isVoidControl = preg_match('/^<x-forms\.(?:input|date-input)\b/', $tag) === 1;
                    $isNotSelfClosing = $isVoidControl && ! str_ends_with(rtrim(substr($tag, 0, -1)), '/');

                    return $hasUnsafeDirective || $isNotSelfClosing;
                })
                ->map(fn (string $tag): string => $file->getRelativePathname().': '.preg_replace('/\s+/', ' ', $tag))
                ->all();
        })
        ->values()
        ->all();

    expect($violations)->toBe([]);
});
