@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $title = __("suppliers.{$mode}");
    $value = fn ($field, $default = '') => old($field, $record?->{$field} ?? $default);
    $linkedAccount = $record?->account;
    $groupAccount = $record?->accountGroup;
    if (! $groupAccount && $linkedAccount) {
        $groupAccount = app(\Modules\Accounting\Services\BusinessPartnerAccountService::class)
            ->linkedAccountGroup(\Modules\Accounting\Services\BusinessPartnerAccountService::Supplier, $linkedAccount);
    }
    $groupOption = $groupAccount ? ['id' => $groupAccount->doc_num, 'text' => $groupAccount->codeNameLabel()] : null;
    $linkedAccountDisplay = $linkedAccount?->codeNameLabel();
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $locationOption = fn ($relation) => $record?->{$relation} ? ['id' => $record->{$relation}->doc_num, 'text' => $record->{$relation}->name] : null;
    $creditLimitRows = old('credit_limits', $record?->creditLimits?->map(fn ($limit) => [
        'currency_doc_num' => $limit->currency?->doc_num,
        'currency_text' => $limit->currency ? trim(implode(' — ', array_filter([$limit->currency->code, $limit->currency->name]))) : null,
        'credit_limit' => $limit->credit_limit,
        'notes' => $limit->notes,
    ])->values()->all() ?? []);
@endphp

@section('title', $title)

@push('styles')
    <style>
        .business-partner-form-card .business-partner-group-control {
            display: flex;
            align-items: flex-start;
            gap: .5rem;
        }

        .business-partner-form-card .business-partner-group-control .select2-container {
            flex: 1 1 auto;
            min-width: 0;
        }

        .business-partner-form-card .business-partner-group-control .btn {
            flex: 0 0 auto;
            white-space: nowrap;
        }

        .business-partner-form-card .business-partner-location-field .business-partner-group-control .btn {
            align-self: stretch;
            width: 2.25rem;
            padding-right: 0;
            padding-left: 0;
        }

        .business-partner-form-card .business-partner-credit-tab {
            min-width: 0;
        }

        .business-partner-form-card .business-partner-credit-limits-table .select2-container {
            min-width: 14rem;
        }
    </style>
@endpush

@section('content')
    <form class="js-business-partner-form js-crud-form" action="{{ $action }}" method="{{ $method }}" data-resource="suppliers" data-primary-focus="name" data-mode="{{ $mode }}" novalidate>
        @csrf
        @if($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if($cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card mb-3 business-partner-form-card">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col"><h5 class="mb-0">{{ $title }}</h5></div>
                    <div class="col-auto">@include('modules.finance.partials.form-actions', ['resource' => 'suppliers', 'routePrefix' => 'admin.purchases.suppliers'])</div>
                </div>
            </div>
            <div class="card-body">
                <div class="alert d-none js-form-alert"><div class="js-form-alert-message"></div></div>
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#supplier-basic-tab" type="button" role="tab">{{ __('suppliers.tabs.basic_data') }}</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#supplier-credit-limits-tab" type="button" role="tab">{{ __('suppliers.tabs.credit_limits') }}</button></li>
                </ul>
                <div class="tab-content border-x border-bottom p-3">
                    <div class="tab-pane fade show active" id="supplier-basic-tab" role="tabpanel">
                <div class="row g-3">
                    @if($canControlDocumentNumber)
                        <div class="col-md-2">
                            <label class="form-label" for="doc_number">{{ __('suppliers.attributes.doc_number') }}</label>
                            @if($isView)
                                <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                            @else
                                <input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="0" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}">
                            @endif
                            <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                        </div>
                    @elseif(! $isCreateLike)
                        <div class="col-md-2">
                            <x-forms.view-field for="doc_num" :label="__('suppliers.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center" />
                        </div>
                    @endif

                    <div class="{{ $canControlDocumentNumber || ! $isCreateLike ? 'col-md-7' : 'col-md-9' }}">
                        <x-forms.label for="name" :label="__('suppliers.attributes.name')" required />
                        @if($isView)
                            <x-forms.view-field for="name" :value="$value('name')" />
                        @else
                            <input class="form-control" id="name" name="name" value="{{ $value('name') }}" autofocus required>
                        @endif
                        <div class="invalid-feedback" data-error-for="name"></div>
                    </div>

                    <div class="col-md-3">
                        <x-forms.label for="status" :label="__('suppliers.attributes.status')" required />
                        @if($isView)
                            <x-forms.view-field for="status" :value="__('business_partners.statuses.'.($record?->status ?? 'active'))" />
                        @else
                            <select class="form-select" id="status" name="status" required>
                                <option value="active" @selected($value('status', 'active') === 'active')>{{ __('business_partners.statuses.active') }}</option>
                                <option value="inactive" @selected($value('status') === 'inactive')>{{ __('business_partners.statuses.inactive') }}</option>
                            </select>
                        @endif
                        <div class="invalid-feedback" data-error-for="status"></div>
                    </div>

                    <div class="col-md-4">
                        <x-forms.label for="account_group_doc_num" :label="__('suppliers.attributes.account_group')" />
                        @if($isView)
                            <x-forms.view-field for="account_group_doc_num" :value="$groupOption['text'] ?? null" />
                        @else
                            <div class="business-partner-group-control">
                                <select class="form-select js-select2-ajax js-business-partner-group-select" id="account_group_doc_num" name="account_group_doc_num" data-url="{{ route('admin.purchases.select2.supplier-groups') }}" data-placeholder="{{ __('suppliers.placeholders.supplier_group') }}" data-allow-clear="true">
                                    @if($groupOption)
                                        <option value="{{ $groupOption['id'] }}" selected>{{ $groupOption['text'] }}</option>
                                    @endif
                                </select>
                                @if($canCreateAccounts)
                                    <button class="btn btn-falcon-default btn-sm js-business-partner-inline-create" type="button" data-modal="#supplier-inline-modal">
                                        <span class="fas fa-plus"></span><span class="ms-1">{{ __('suppliers.actions.add_group') }}</span>
                                    </button>
                                @endif
                            </div>
                            <div class="form-text">{{ __('suppliers.account_parent_help') }}</div>
                        @endif
                        <div class="invalid-feedback d-block" data-error-for="account_group_doc_num"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="phone">{{ __('suppliers.attributes.phone') }}</label>
                        @if($isView)
                            <x-forms.view-field for="phone" :value="$value('phone')" />
                        @else
                            <input class="form-control" id="phone" name="phone" value="{{ $value('phone') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="phone"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="mobile">{{ __('suppliers.attributes.mobile') }}</label>
                        @if($isView)
                            <x-forms.view-field for="mobile" :value="$value('mobile')" />
                        @else
                            <input class="form-control" id="mobile" name="mobile" value="{{ $value('mobile') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="mobile"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="email">{{ __('suppliers.attributes.email') }}</label>
                        @if($isView)
                            <x-forms.view-field for="email" :value="$value('email')" />
                        @else
                            <input class="form-control" id="email" name="email" type="email" value="{{ $value('email') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="email"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="tax_number">{{ __('suppliers.attributes.tax_number') }}</label>
                        @if($isView)
                            <x-forms.view-field for="tax_number" :value="$value('tax_number')" />
                        @else
                            <input class="form-control" id="tax_number" name="tax_number" value="{{ $value('tax_number') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="tax_number"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="commercial_register">{{ __('suppliers.attributes.commercial_register') }}</label>
                        @if($isView)
                            <x-forms.view-field for="commercial_register" :value="$value('commercial_register')" />
                        @else
                            <input class="form-control" id="commercial_register" name="commercial_register" value="{{ $value('commercial_register') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="commercial_register"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="national_id">{{ __('suppliers.attributes.national_id') }}</label>
                        @if($isView)
                            <x-forms.view-field for="national_id" :value="$value('national_id')" />
                        @else
                            <input class="form-control" id="national_id" name="national_id" value="{{ $value('national_id') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="national_id"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="contact_person">{{ __('suppliers.attributes.contact_person') }}</label>
                        @if($isView)
                            <x-forms.view-field for="contact_person" :value="$value('contact_person')" />
                        @else
                            <input class="form-control" id="contact_person" name="contact_person" value="{{ $value('contact_person') }}">
                        @endif
                        <div class="invalid-feedback" data-error-for="contact_person"></div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="payment_terms_days">{{ __('Default payment terms (days)') }}</label>
                        @if($isView)
                            <x-forms.view-field for="payment_terms_days" :value="$value('payment_terms_days')" dir="ltr" />
                        @else
                            <input class="form-control" id="payment_terms_days" name="payment_terms_days" type="number" min="0" max="3650" value="{{ $value('payment_terms_days') }}" dir="ltr">
                        @endif
                        <div class="invalid-feedback" data-error-for="payment_terms_days"></div>
                    </div>

                    <div class="col-xl-3 col-md-6 business-partner-location-field">
                        <label class="form-label" for="country_doc_num">{{ __('suppliers.attributes.country') }}</label>
                        @if($isView)
                            <x-forms.view-field for="country_doc_num" :value="$locationOption('country')['text'] ?? null" />
                        @else
                            <div class="business-partner-group-control">
                                <select class="form-select js-select2-ajax js-location-country" id="country_doc_num" name="country_doc_num" data-url="{{ route('admin.select2.countries') }}" data-placeholder="{{ __('suppliers.placeholders.country') }}" data-allow-clear="true">
                                    @if($locationOption('country'))<option value="{{ $locationOption('country')['id'] }}" selected>{{ $locationOption('country')['text'] }}</option>@endif
                                </select>
                                <button class="btn btn-falcon-default btn-sm js-business-location-inline-create" type="button" data-type="countries" data-title="{{ __('suppliers.actions.add_country') }}" data-url="{{ route('admin.select2.inline.locations.store', 'countries') }}" data-target-select="#country_doc_num" data-modal="#supplier-location-inline-modal"><span class="fas fa-plus"></span></button>
                            </div>
                        @endif
                        <div class="invalid-feedback" data-error-for="country_doc_num"></div>
                    </div>

                    <div class="col-xl-3 col-md-6 business-partner-location-field">
                        <label class="form-label" for="governorate_doc_num">{{ __('suppliers.attributes.governorate') }}</label>
                        @if($isView)
                            <x-forms.view-field for="governorate_doc_num" :value="$locationOption('governorate')['text'] ?? null" />
                        @else
                            <div class="business-partner-group-control">
                                <select class="form-select js-select2-ajax js-location-governorate" id="governorate_doc_num" name="governorate_doc_num" data-url="{{ route('admin.select2.governorates') }}" data-placeholder="{{ __('suppliers.placeholders.governorate') }}" data-allow-clear="true">
                                    @if($locationOption('governorate'))<option value="{{ $locationOption('governorate')['id'] }}" selected>{{ $locationOption('governorate')['text'] }}</option>@endif
                                </select>
                                <button class="btn btn-falcon-default btn-sm js-business-location-inline-create" type="button" data-type="governorates" data-title="{{ __('suppliers.actions.add_governorate') }}" data-url="{{ route('admin.select2.inline.locations.store', 'governorates') }}" data-target-select="#governorate_doc_num" data-parent-select="#country_doc_num" data-parent-field="country_doc_num" data-modal="#supplier-location-inline-modal"><span class="fas fa-plus"></span></button>
                            </div>
                        @endif
                        <div class="invalid-feedback" data-error-for="governorate_doc_num"></div>
                    </div>

                    <div class="col-xl-3 col-md-6 business-partner-location-field">
                        <label class="form-label" for="city_doc_num">{{ __('suppliers.attributes.city') }}</label>
                        @if($isView)
                            <x-forms.view-field for="city_doc_num" :value="$locationOption('cityLookup')['text'] ?? null" />
                        @else
                            <div class="business-partner-group-control">
                                <select class="form-select js-select2-ajax js-location-city" id="city_doc_num" name="city_doc_num" data-url="{{ route('admin.select2.cities') }}" data-placeholder="{{ __('suppliers.placeholders.city') }}" data-allow-clear="true">
                                    @if($locationOption('cityLookup'))<option value="{{ $locationOption('cityLookup')['id'] }}" selected>{{ $locationOption('cityLookup')['text'] }}</option>@endif
                                </select>
                                <button class="btn btn-falcon-default btn-sm js-business-location-inline-create" type="button" data-type="cities" data-title="{{ __('suppliers.actions.add_city') }}" data-url="{{ route('admin.select2.inline.locations.store', 'cities') }}" data-target-select="#city_doc_num" data-parent-select="#governorate_doc_num" data-parent-field="governorate_doc_num" data-modal="#supplier-location-inline-modal"><span class="fas fa-plus"></span></button>
                            </div>
                        @endif
                        <div class="invalid-feedback" data-error-for="city_doc_num"></div>
                    </div>

                    <div class="col-xl-3 col-md-6 business-partner-location-field">
                        <label class="form-label" for="area_doc_num">{{ __('suppliers.attributes.area') }}</label>
                        @if($isView)
                            <x-forms.view-field for="area_doc_num" :value="$locationOption('area')['text'] ?? null" />
                        @else
                            <div class="business-partner-group-control">
                                <select class="form-select js-select2-ajax js-location-area" id="area_doc_num" name="area_doc_num" data-url="{{ route('admin.select2.areas') }}" data-placeholder="{{ __('suppliers.placeholders.area') }}" data-allow-clear="true">
                                    @if($locationOption('area'))<option value="{{ $locationOption('area')['id'] }}" selected>{{ $locationOption('area')['text'] }}</option>@endif
                                </select>
                                <button class="btn btn-falcon-default btn-sm js-business-location-inline-create" type="button" data-type="areas" data-title="{{ __('suppliers.actions.add_area') }}" data-url="{{ route('admin.select2.inline.locations.store', 'areas') }}" data-target-select="#area_doc_num" data-parent-select="#city_doc_num" data-parent-field="city_doc_num" data-modal="#supplier-location-inline-modal"><span class="fas fa-plus"></span></button>
                            </div>
                        @endif
                        <div class="invalid-feedback" data-error-for="area_doc_num"></div>
                    </div>

                    @if($linkedAccountDisplay)
                        <div class="col-12">
                            <x-forms.view-field for="linked_account" :label="__('suppliers.attributes.account')" :value="$linkedAccountDisplay" />
                        </div>
                    @endif

                    <div class="col-12">
                        <label class="form-label" for="address">{{ __('suppliers.attributes.address') }}</label>
                        @if($isView)
                            <x-forms.view-field for="address" as="textarea" :value="$value('address')" rows="2" />
                        @else
                            <textarea class="form-control" id="address" name="address" rows="2">{{ $value('address') }}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="address"></div>
                    </div>

                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('suppliers.attributes.notes') }}</label>
                        @if($isView)
                            <x-forms.view-field for="notes" as="textarea" :value="$value('notes')" rows="3" />
                        @else
                            <textarea class="form-control" id="notes" name="notes" rows="3">{{ $value('notes') }}</textarea>
                        @endif
                        <div class="invalid-feedback" data-error-for="notes"></div>
                    </div>
                </div>
                    </div>
                    <div class="tab-pane fade business-partner-credit-tab" id="supplier-credit-limits-tab" role="tabpanel">
                        @include('modules.business-partners.partials.credit-limits', ['rows' => $creditLimitRows, 'isView' => $isView, 'resource' => 'suppliers'])
                    </div>
                </div>

                @if($mode === 'edit' || $isView)
                    <x-audit-fields-row
                        :metadata="$metadata"
                        :show-deleted="$isView && ($record?->trashed() ?? false)"
                        :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                    />
                @endif
            </div>
            <div class="card-footer">@include('modules.finance.partials.form-actions', ['resource' => 'suppliers', 'routePrefix' => 'admin.purchases.suppliers'])</div>
        </div>
    </form>

    @unless($isView)
        <div class="modal fade" id="supplier-inline-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form class="modal-content js-business-partner-inline-form" action="{{ route('admin.purchases.suppliers.account-groups.store') }}" method="POST" data-target-select="#account_group_doc_num" novalidate>
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">{{ __('suppliers.actions.add_group') }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('common.actions.close') }}"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert d-none js-form-alert"><div class="js-form-alert-message"></div></div>
                        <div class="mb-3">
                            <x-forms.label for="supplier_inline_name" :label="__('suppliers.attributes.account_group_name')" required />
                            <input class="form-control" id="supplier_inline_name" name="name" type="text" required>
                            <div class="invalid-feedback" data-error-for="name"></div>
                        </div>
                        <div>
                            <label class="form-label" for="supplier_inline_notes">{{ __('suppliers.attributes.notes') }}</label>
                            <textarea class="form-control" id="supplier_inline_notes" name="notes" rows="3"></textarea>
                            <div class="invalid-feedback" data-error-for="notes"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-falcon-default" data-bs-dismiss="modal">{{ __('common.actions.cancel') }}</button>
                        <button type="submit" class="btn btn-primary"><span class="fas fa-save me-1"></span>{{ __('common.actions.save') }}</button>
                    </div>
                </form>
            </div>
        </div>
        @include('modules.business-partners.partials.location-inline-modal', ['modalId' => 'supplier-location-inline-modal'])
    @endunless
@endsection

@push('scripts')
    <script>
        window.businessPartnerMessages = @json(__('business_partners.js'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('vendors/select2/select2.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Purchases/suppliers.js') }}"></script>
@endpush
