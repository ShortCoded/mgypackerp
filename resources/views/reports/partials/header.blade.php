@php
    $isRtl = ($direction ?? 'ltr') === 'rtl';
    $logoPath = is_string($companyLogoPath ?? null)
        && (str_starts_with($companyLogoPath, 'data:image/') || is_file($companyLogoPath))
            ? $companyLogoPath
            : null;
    $logoHtml = $logoPath
        ? '<img class="report-logo" src="'.e($logoPath).'" alt="" style="max-width:110px;max-height:60px;width:auto;height:auto;object-fit:contain;">'
        : '<span class="report-company-name">'.e($companyName).'</span>';
    if (! ($showCompanyIdentity ?? true)) {
        $logoHtml = '';
    }
@endphp
@php($headerTitle = ($customerFacing ?? false) ? ($documentHeaderTitle ?? $reportTitle ?? $title) : ($reportTitle ?? $title))

<table class="report-header" dir="ltr" style="width:100%;border-collapse:collapse;table-layout:fixed;">
    <tr>
        @if ($isRtl)
            <td class="report-header-side report-meta" dir="rtl" style="width:32%;text-align:left;vertical-align:middle;">
                <span>{{ __('reports.print_date') }}</span><br><strong>{{ $printDate }}</strong>
            </td>
            <td class="report-title" dir="rtl" style="width:36%;text-align:center;vertical-align:middle;color:#14335c;font-weight:700;">{{ $headerTitle }}</td>
            <td class="report-header-side report-header-logo" dir="rtl" style="width:32%;text-align:right;vertical-align:middle;">{!! $logoHtml !!}@if(($showCompanyIdentity ?? true) && $logoPath)<br><span class="report-company-name">{{ $companyName }}</span>@endif</td>
        @else
            <td class="report-header-side report-header-logo" style="width:32%;text-align:left;vertical-align:middle;">{!! $logoHtml !!}@if(($showCompanyIdentity ?? true) && $logoPath)<br><span class="report-company-name">{{ $companyName }}</span>@endif</td>
            <td class="report-title" style="width:36%;text-align:center;vertical-align:middle;color:#14335c;font-weight:700;">{{ $headerTitle }}</td>
            <td class="report-header-side report-meta" style="width:32%;text-align:right;vertical-align:middle;">
                <span>{{ __('reports.print_date') }}</span><br><strong>{{ $printDate }}</strong>
            </td>
        @endif
    </tr>
</table>
<div class="report-header-rule" style="border-bottom:2px solid #0b8f94;margin-top:8px;height:1px;"></div>
