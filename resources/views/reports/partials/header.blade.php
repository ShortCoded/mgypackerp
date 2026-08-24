@php
    $isRtl = ($direction ?? 'ltr') === 'rtl';
    $logoPath = is_string($companyLogoPath ?? null)
        && (str_starts_with($companyLogoPath, 'data:image/') || is_file($companyLogoPath))
            ? $companyLogoPath
            : null;
    $logoHtml = $logoPath
        ? '<img class="report-logo" src="'.e($logoPath).'" alt="'.e($companyName).'" style="max-width:110px;max-height:60px;width:auto;height:auto;object-fit:contain;">'
        : '<span class="report-company-name">'.e($companyName).'</span>';
@endphp

<table class="report-header" style="width:100%;border-collapse:collapse;">
    <tr>
        @if ($isRtl)
            <td class="report-header-side report-header-logo" style="width:32%;text-align:right;vertical-align:middle;">{!! $logoHtml !!}</td>
            <td class="report-title" style="width:36%;text-align:center;vertical-align:middle;">{{ $reportTitle ?? $title }}</td>
            <td class="report-header-side report-meta" style="width:32%;text-align:left;vertical-align:middle;">
                <span>{{ __('reports.print_date') }}</span><br><strong>{{ $printDate }}</strong>
            </td>
        @else
            <td class="report-header-side report-header-logo" style="width:32%;text-align:left;vertical-align:middle;">{!! $logoHtml !!}</td>
            <td class="report-title" style="width:36%;text-align:center;vertical-align:middle;">{{ $reportTitle ?? $title }}</td>
            <td class="report-header-side report-meta" style="width:32%;text-align:right;vertical-align:middle;">
                <span>{{ __('reports.print_date') }}</span><br><strong>{{ $printDate }}</strong>
            </td>
        @endif
    </tr>
</table>
<div class="report-header-rule" style="border-bottom:1px solid #94a3b8;margin-top:6px;height:1px;"></div>
