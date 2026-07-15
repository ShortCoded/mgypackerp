@php
    $context = $appOperatingContext ?? ['company' => null, 'branch' => null, 'financial_period' => null, 'requires_selection' => true];
    $currentCompany = $context['company'] ?? null;
    $currentBranch = $context['branch'] ?? null;
    $currentFinancialPeriod = $context['financial_period'] ?? null;
    $currentCompanyDocNum = $currentCompany['doc_num'] ?? $currentCompany['id'] ?? null;
@endphp

<div class="modal fade" id="operatingContextModal" tabindex="-1" aria-labelledby="operatingContextModalLabel" aria-hidden="true" data-operating-context-modal>
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="operating-context-form" novalidate>
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="operatingContextModalLabel">{{ __('operating_context.select_context') }}</h5>
                        <p class="mb-0 fs-10 text-600">{{ __('operating_context.helper') }}</p>
                    </div>
                    <button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}" data-operating-context-dismiss></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning py-2 d-none" role="alert" data-operating-context-alert></div>

                    <div class="mb-3">
                        <label class="form-label" for="operating-context-company">{{ __('operating_context.select_company') }} <span class="text-danger">*</span></label>
                        <select class="form-select js-select2-ajax" id="operating-context-company" name="company_doc_num" data-url="{{ route('admin.select2.companies', ['access_scope' => 'operating_scope']) }}" data-placeholder="{{ __('operating_context.select_company') }}" data-allow-clear="true" required>
                            @if ($currentCompany)
                                <option value="{{ $currentCompanyDocNum }}" selected>{{ $currentCompany['label'] ?? $currentCompany['name'] ?? $currentCompanyDocNum }}</option>
                            @endif
                        </select>
                        <div class="invalid-feedback d-block" data-error-for="company_doc_num"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="operating-context-branch">{{ __('operating_context.select_branch') }} <span class="text-danger">*</span></label>
                        <select class="form-select js-select2-ajax" id="operating-context-branch" name="branch_doc_num" data-url="{{ route('admin.select2.branches', ['access_scope' => 'operating_scope']) }}" data-placeholder="{{ __('operating_context.select_branch') }}" data-allow-clear="true" data-depends-on="#operating-context-company" data-dependent-param="company_doc_num" data-dependent-result-field="company_doc_num" data-disable-when-dependency-empty="true" required @disabled(! $currentCompanyDocNum)>
                            @if ($currentBranch)
                                <option value="{{ $currentBranch['doc_num'] ?? $currentBranch['id'] }}" data-dependent-value="{{ $currentBranch['company_doc_num'] ?? $currentCompanyDocNum }}" selected>{{ $currentBranch['label'] ?? $currentBranch['name'] ?? $currentBranch['doc_num'] ?? $currentBranch['id'] }}</option>
                            @endif
                        </select>
                        <div class="invalid-feedback d-block" data-error-for="branch_doc_num"></div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="operating-context-financial-period">{{ __('operating_context.select_financial_period') }} <span class="text-danger">*</span></label>
                        <select class="form-select js-select2-ajax" id="operating-context-financial-period" name="financial_period_doc_num" data-url="{{ route('admin.select2.financial-periods', ['access_scope' => 'operating_scope']) }}" data-placeholder="{{ __('operating_context.select_financial_period') }}" data-allow-clear="true" data-depends-on="#operating-context-company" data-dependent-param="company_doc_num" data-dependent-result-field="company_doc_num" data-disable-when-dependency-empty="true" required @disabled(! $currentCompanyDocNum)>
                            @if ($currentFinancialPeriod)
                                <option value="{{ $currentFinancialPeriod['doc_num'] ?? $currentFinancialPeriod['id'] }}" data-dependent-value="{{ $currentCompanyDocNum }}" selected>{{ $currentFinancialPeriod['label'] ?? $currentFinancialPeriod['name'] ?? $currentFinancialPeriod['doc_num'] ?? $currentFinancialPeriod['id'] }}</option>
                            @endif
                        </select>
                        <div class="invalid-feedback d-block" data-error-for="financial_period_doc_num"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-falcon-default" type="button" data-bs-dismiss="modal" data-operating-context-cancel>
                        {{ __('common.actions.cancel') }}
                    </button>
                    <button class="btn btn-primary" type="submit">
                        <span class="fas fa-check me-1"></span>{{ __('operating_context.save_context') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
