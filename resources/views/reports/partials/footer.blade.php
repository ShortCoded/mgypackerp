@php
    $isRtl = ($direction ?? 'ltr') === 'rtl';
@endphp

<div class="report-footer-rule" style="border-top:1px solid #cbd5e1;margin-bottom:5px;height:1px;"></div>
<table class="report-footer" style="width:100%;border-collapse:collapse;">
    <tr>
        @if ($isRtl)
            <td class="report-page-number" style="width:30%;text-align:right;vertical-align:top;">
                {{ __('reports.page') }} {PAGENO}/{nbpg}
            </td>
            <td class="report-footer-company" style="width:40%;text-align:center;vertical-align:top;">
                @if($showCompanyIdentity ?? true)<strong>{{ __('reports.company') }}:</strong> {{ $companyName }}@endif
            </td>
            <td class="report-footer-info" style="width:30%;text-align:left;vertical-align:top;">
                @unless($customerFacing ?? false)<strong>{{ __('reports.generated_by') }}:</strong> {{ $generatedByName }}@endunless
            </td>
        @else
            <td class="report-footer-info" style="width:30%;text-align:left;vertical-align:top;">
                @unless($customerFacing ?? false)<strong>{{ __('reports.generated_by') }}:</strong> {{ $generatedByName }}@endunless
            </td>
            <td class="report-footer-company" style="width:40%;text-align:center;vertical-align:top;">
                @if($showCompanyIdentity ?? true)<strong>{{ __('reports.company') }}:</strong> {{ $companyName }}@endif
            </td>
            <td class="report-page-number" style="width:30%;text-align:right;vertical-align:top;">
                {{ __('reports.page') }} {PAGENO}/{nbpg}
            </td>
        @endif
    </tr>
</table>
