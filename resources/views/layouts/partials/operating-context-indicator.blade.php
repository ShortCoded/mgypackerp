@php
    $context = $appOperatingContext ?? ['company' => null, 'branch' => null, 'financial_period' => null, 'requires_selection' => true];
    $companyLabel = $context['company']['name'] ?? __('operating_context.not_selected');
    $branchLabel = $context['branch']['name'] ?? __('operating_context.not_selected');
    $periodLabel = $context['financial_period']['name'] ?? __('operating_context.not_selected');
    $requiresSelection = (bool) ($context['requires_selection'] ?? true);
@endphp

<li class="nav-item d-flex align-items-center">
    <button
        class="btn btn-falcon-default btn-sm erp-operating-context-trigger {{ $requiresSelection ? 'is-missing' : '' }}"
        type="button"
        data-operating-context-trigger
        title="{{ __('operating_context.change_context') }}"
        data-bs-title="{{ __('operating_context.change_context') }}"
    >
        <span class="fas fa-building text-primary me-1"></span>
        <span class="erp-operating-context-line">
            <span class="text-600">{{ __('operating_context.current_company') }}:</span>
            <span class="fw-semibold" data-operating-context-company>{{ $companyLabel }}</span>
        </span>
        <span class="vr mx-2"></span>
        <span class="fas fa-code-branch text-primary me-1"></span>
        <span class="erp-operating-context-line">
            <span class="text-600">{{ __('operating_context.current_branch') }}:</span>
            <span class="fw-semibold" data-operating-context-branch>{{ $branchLabel }}</span>
        </span>
        <span class="vr mx-2"></span>
        <span class="fas fa-calendar-alt text-primary me-1"></span>
        <span class="erp-operating-context-line">
            <span class="text-600">{{ __('operating_context.current_financial_period') }}:</span>
            <span class="fw-semibold" data-operating-context-period>{{ $periodLabel }}</span>
        </span>
    </button>
</li>
