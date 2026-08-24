@extends('layouts.app')

@php
    use Modules\Core\Services\AssetVersionService;
    use Modules\Core\Services\DateFormatService;
    use Modules\Core\Services\NumericFormatService;
    use Modules\Core\Services\SettingService;
    use Modules\Sales\Models\Quotation;
    use Modules\Sales\Models\QuotationPaymentMilestone;
    use Modules\Sales\Models\QuotationRevision;

    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isView = $mode === 'view';
    $isTrashed = $record?->trashed() ?? false;
    $isReadonly = $isView || (! $isCreateLike && ($isRevisionLocked ?? false)) || $isTrashed;
    $title = match ($mode) {
        'edit' => __('quotations.edit'),
        'view' => __('quotations.view'),
        'clone' => __('quotations.clone'),
        default => __('quotations.create'),
    };
    $dates = app(DateFormatService::class);
    $numbers = app(NumericFormatService::class);
    $settings = app(SettingService::class);
    $asset = app(AssetVersionService::class);
    $editorDirection = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
    $dateValue = fn ($field, $default = null) => old($field, $default ? $dates->formatDate($default, '') : '');
    $plainDate = fn ($date) => $date ? $dates->formatDate($date, '') : '';
    $documentNumberValue = old('doc_number', ! $isCreateLike ? $record?->doc_number : '');
    $currentRevision = $revision;
    $richValue = fn ($field, $snapshot) => old($field, $isCreateLike ? ($snapshot ?? '') : ($snapshot ?? ''));
    $richDisplay = fn ($value) => trim((string) $value) !== '' ? $value : e(__('common.empty_value'));
    $discountType = old('discount_type', $currentRevision?->discount_type);
    $formatFileSize = static function (int $bytes): string {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    };
@endphp

@section('title', $title)

@push('styles')
    <link href="{{ $asset->url('vendors/summernote/summernote-bs5.min.css') }}" rel="stylesheet">
    <style>
        .quotation-lines-table th,
        .quotation-lines-table td,
        .quotation-simple-grid th,
        .quotation-simple-grid td {
            min-width: 8rem;
            vertical-align: top;
        }

        .quotation-lines-table .quotation-product-cell {
            min-width: 18rem;
        }

        .quotation-lines-table .quotation-description-cell {
            min-width: 16rem;
        }

        .quotation-lines-table {
            min-width: 2500px;
        }

        .quotation-total-box {
            max-width: 24rem;
        }
    </style>
@endpush

@section('content')
    <form class="js-quotation-form"
        action="{{ $action }}"
        method="{{ $method }}"
        data-mode="{{ $mode }}"
        data-primary-focus="quotation_date"
        novalidate>
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif
        <input type="hidden" name="submit_action" value="save">
        @if ($cloneSourceToken)
            <input type="hidden" name="clone_source_token" value="{{ $cloneSourceToken }}">
        @endif

        <div class="card mb-3">
            <div class="card-header">
                <div class="row flex-between-center g-2">
                    <div class="col">
                        <h5 class="mb-0">{{ $title }}</h5>
                    </div>
                    <div class="col-auto">
                        @include('modules.sales.quotations.partials.form-actions', [
                            'canEditRecord' => ! ($isRevisionLocked ?? false),
                            'canDeleteRecord' => true,
                        ])
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="alert d-none js-form-alert">
                    <div class="js-form-alert-message"></div>
                </div>

                @if (! $isCreateLike && ($isRevisionLocked ?? false) && ! $isTrashed)
                    <div class="alert alert-subtle-warning d-flex flex-wrap gap-2 align-items-center justify-content-between">
                        <span>{{ __('quotations.messages.revision_not_draft') }}</span>
                        @can('quotations.revisions.create')
                            <button class="btn btn-falcon-warning btn-sm js-create-quotation-revision" type="button" data-url="{{ route('admin.sales.quotations.revisions.create', $record->doc_num) }}">
                                <span class="fas fa-code-branch me-1"></span>{{ __('quotations.actions.create_revision') }}
                            </button>
                        @endcan
                    </div>
                @endif

                @if ($isView && $record && ! $isTrashed)
                    <div class="mb-3 d-flex flex-wrap gap-2">
                        @if ($record->status === Quotation::StatusDraft)
                            @can('quotations.mark_sent')
                                <button class="btn btn-falcon-info btn-sm js-quotation-status-action" type="button" data-url="{{ route('admin.sales.quotations.mark-sent', $record->doc_num) }}">
                                    <span class="fas fa-paper-plane me-1"></span>{{ __('quotations.actions.mark_sent') }}
                                </button>
                            @endcan
                        @endif
                        @if (in_array($record->status, [Quotation::StatusSent, Quotation::StatusUnderReview], true))
                            @can('quotations.accept')
                                <button class="btn btn-falcon-success btn-sm js-quotation-status-action" type="button" data-url="{{ route('admin.sales.quotations.accept', $record->doc_num) }}">
                                    <span class="fas fa-check me-1"></span>{{ __('quotations.actions.accept') }}
                                </button>
                            @endcan
                            @can('quotations.reject')
                                <button class="btn btn-falcon-danger btn-sm js-quotation-status-action" type="button" data-url="{{ route('admin.sales.quotations.reject', $record->doc_num) }}">
                                    <span class="fas fa-times me-1"></span>{{ __('quotations.actions.reject') }}
                                </button>
                            @endcan
                        @endif
                        @if ($record->status === Quotation::StatusAccepted)
                            @can('sales_orders.create')
                                <button class="btn btn-success btn-sm js-quotation-convert-action" type="button" data-url="{{ route('admin.sales.quotations.convert', $record) }}">
                                    <span class="fas fa-file-signature me-1"></span>{{ __('quotations.actions.create_sales_order') }}
                                </button>
                            @endcan
                        @endif
                        @if ($record->status === Quotation::StatusConverted && $record->salesOrders->isNotEmpty())
                            @can('sales_orders.view')
                                <a class="btn btn-falcon-success btn-sm" href="{{ route('admin.sales.sales-orders.show', $record->salesOrders->first()) }}">
                                    <span class="fas fa-external-link-alt me-1"></span>{{ $record->salesOrders->first()->doc_num }}
                                </a>
                            @endcan
                        @endif
                        @if (! in_array($record->status, [Quotation::StatusCancelled, Quotation::StatusConverted], true))
                            @can('quotations.cancel')
                                <button class="btn btn-falcon-default text-danger btn-sm js-quotation-status-action" type="button" data-url="{{ route('admin.sales.quotations.cancel', $record->doc_num) }}">
                                    <span class="fas fa-ban me-1"></span>{{ __('quotations.actions.cancel') }}
                                </button>
                            @endcan
                        @endif
                        @can('quotations.print')
                            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.quotations.print', $record) }}" target="_blank">
                                <span class="fas fa-print me-1"></span>{{ __('quotations.actions.print') }}
                            </a>
                        @endcan
                    </div>
                @endif

                <ul class="nav nav-tabs" id="quotation-form-tabs" role="tablist">
                    @foreach (['basic', 'lines', 'payments', 'execution', 'terms', 'attachments', 'revisions'] as $tab)
                        <li class="nav-item" role="presentation">
                            <button class="nav-link @if($loop->first) active @endif" id="quotation-{{ $tab }}-tab" data-bs-toggle="tab" data-bs-target="#quotation-{{ $tab }}" type="button" role="tab" aria-controls="quotation-{{ $tab }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                                {{ __("quotations.tabs.{$tab}") }}
                            </button>
                        </li>
                    @endforeach
                </ul>

                <div class="tab-content pt-3">
                    <div class="tab-pane fade show active" id="quotation-basic" role="tabpanel" aria-labelledby="quotation-basic-tab">
                        <div class="row g-3">
                            @if ($canControlDocumentNumber)
                                <div class="col-md-3 col-xl-2">
                                    <label class="form-label" for="doc_number">{{ __('quotations.attributes.doc_number') }}</label>
                                    @if ($isReadonly)
                                        <x-forms.view-field for="doc_number" as="display" :value="$documentNumberValue" input-class="text-center" />
                                    @else
                                        <input class="form-control text-center" id="doc_number" name="doc_number" type="number" min="1" step="1" inputmode="numeric" value="{{ $documentNumberValue }}" placeholder="{{ __('item_lookups.document_number_control.placeholder') }}">
                                    @endif
                                    <div class="invalid-feedback d-block" data-error-for="doc_number"></div>
                                </div>
                            @elseif (! $isCreateLike)
                                <div class="col-md-3 col-xl-2">
                                    <x-forms.view-field for="doc_num" :label="__('quotations.attributes.doc_num')" :value="$record?->doc_num" input-class="text-center dt-code-value" dir="ltr" />
                                </div>
                            @endif

                            @if (! $isCreateLike)
                                <div class="col-md-3 col-xl-2">
                                    <x-forms.view-field for="status" :label="__('quotations.attributes.status')" :value="__('quotations.statuses.'.($record?->status ?? 'draft'))" />
                                </div>
                                <div class="col-md-3 col-xl-2">
                                    <x-forms.view-field for="revision" :label="__('quotations.attributes.current_revision')" :value="$record?->currentRevision?->revision_code" dir="ltr" />
                                </div>
                            @endif

                            <div class="col-md-3">
                                <x-forms.label for="quotation_date" :label="__('quotations.attributes.quotation_date')" required />
                                @if ($isReadonly)
                                    <x-forms.view-field for="quotation_date" :value="$plainDate($record?->quotation_date)" dir="ltr" input-class="date-value text-center" />
                                @else
                                    <input class="form-control text-center js-date-picker" id="quotation_date" name="quotation_date" type="text" value="{{ $dateValue('quotation_date', $isCreateLike ? now() : $record?->quotation_date) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="quotation_date"></div>
                            </div>

                            <div class="col-md-3">
                                <x-forms.label for="valid_until" :label="__('quotations.attributes.valid_until')" />
                                @if ($isReadonly)
                                    <x-forms.view-field for="valid_until" :value="$plainDate($record?->valid_until)" dir="ltr" input-class="date-value text-center" />
                                @else
                                    <input class="form-control text-center js-date-picker" id="valid_until" name="valid_until" type="text" value="{{ $dateValue('valid_until', $record?->valid_until) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr">
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="valid_until"></div>
                            </div>

                            <div class="col-md-6">
                                <x-forms.label for="customer_doc_num" :label="__('quotations.attributes.customer')" />
                                @if ($isReadonly)
                                    <x-forms.view-field for="customer_doc_num" :value="$customerOption['text'] ?? null" />
                                @else
                                    <select class="form-select js-select2-ajax" id="customer_doc_num" name="customer_doc_num" data-url="{{ route('admin.sales.select2.customers') }}" data-placeholder="{{ __('quotations.placeholders.customer') }}" data-allow-clear="true">
                                        @if ($customerOption)
                                            <option value="{{ $customerOption['id'] }}" selected>{{ $customerOption['text'] }}</option>
                                        @endif
                                    </select>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="customer_doc_num"></div>
                            </div>

                            <div class="col-md-3">
                                <x-forms.label for="quotation_type" :label="__('quotations.attributes.quotation_type')" required />
                                @if ($isReadonly)
                                    <x-forms.view-field for="quotation_type" :value="__('quotations.types.'.($record?->quotation_type ?? Quotation::TypeStandard))" />
                                @else
                                    <select class="form-select" id="quotation_type" name="quotation_type" required>
                                        @foreach (Quotation::Types as $type)
                                            <option value="{{ $type }}" @selected(old('quotation_type', $record?->quotation_type ?? Quotation::TypeStandard) === $type)>{{ __("quotations.types.{$type}") }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="quotation_type"></div>
                            </div>

                            <div class="col-md-3">
                                <x-forms.label for="currency_doc_num" :label="__('quotations.attributes.currency')" />
                                @if ($isReadonly)
                                    <x-forms.view-field for="currency_doc_num" :value="$currencyOption['text'] ?? null" />
                                @else
                                    <select class="form-select js-select2-ajax" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.select2.currencies') }}" data-placeholder="{{ __('quotations.placeholders.currency') }}" data-allow-clear="true">
                                        @if ($currencyOption)
                                            <option value="{{ $currencyOption['id'] }}" selected>{{ $currencyOption['text'] }}</option>
                                        @endif
                                    </select>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="currency_doc_num"></div>
                            </div>

                            <div class="col-md-3">
                                <x-forms.label for="exchange_rate" :label="__('quotations.attributes.exchange_rate')" required />
                                @if ($isReadonly)
                                    <x-forms.view-field for="exchange_rate" :value="$numbers->format($record?->exchange_rate ?? 1)" dir="ltr" input-class="text-center" />
                                @else
                                    <x-forms.numeric-input class="text-center" id="exchange_rate" name="exchange_rate" :value="old('exchange_rate', $record?->exchange_rate ?? 1)" :scale="6" min="0.000001" step="0.000001" required />
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="exchange_rate"></div>
                            </div>

                            <div class="col-md-3">
                                <x-forms.label for="sales_person_doc_num" :label="__('quotations.attributes.sales_person')" />
                                @if ($isReadonly)
                                    <x-forms.view-field for="sales_person_doc_num" :value="$salesPersonOption['text'] ?? null" />
                                @else
                                    <select class="form-select js-select2-ajax" id="sales_person_doc_num" name="sales_person_doc_num" data-url="{{ route('admin.select2.users') }}" data-placeholder="{{ __('quotations.placeholders.sales_person') }}" data-allow-clear="true">
                                        @if ($salesPersonOption)
                                            <option value="{{ $salesPersonOption['id'] }}" selected>{{ $salesPersonOption['text'] }}</option>
                                        @endif
                                    </select>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="sales_person_doc_num"></div>
                            </div>

                            <div class="col-md-6">
                                <x-forms.label for="subject" :label="__('quotations.attributes.subject')" />
                                @if ($isReadonly)
                                    <x-forms.view-field for="subject" :value="$record?->subject" />
                                @else
                                    <input class="form-control" id="subject" name="subject" type="text" value="{{ old('subject', $record?->subject) }}" maxlength="255">
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="subject"></div>
                            </div>

                            <div class="col-md-6">
                                <x-forms.label for="project_name" :label="__('quotations.attributes.project_name')" />
                                @if ($isReadonly)
                                    <x-forms.view-field for="project_name" :value="$record?->project_name" />
                                @else
                                    <input class="form-control" id="project_name" name="project_name" type="text" value="{{ old('project_name', $record?->project_name) }}" maxlength="255">
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="project_name"></div>
                            </div>

                            <div class="col-md-6">
                                <x-forms.label for="customer_reference" :label="__('quotations.attributes.customer_reference')" />
                                @if ($isReadonly)
                                    <x-forms.view-field for="customer_reference" :value="$record?->customer_reference" />
                                @else
                                    <input class="form-control" id="customer_reference" name="customer_reference" type="text" value="{{ old('customer_reference', $record?->customer_reference) }}" maxlength="160">
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="customer_reference"></div>
                            </div>

                            <div class="col-md-6">
                                <x-forms.label for="internal_notes" :label="__('quotations.attributes.internal_notes')" />
                                @if ($isReadonly)
                                    <x-forms.view-field for="internal_notes" :value="$record?->internal_notes" />
                                @else
                                    <textarea class="form-control" id="internal_notes" name="internal_notes" rows="2">{{ old('internal_notes', $record?->internal_notes) }}</textarea>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="internal_notes"></div>
                            </div>

                            <div class="col-md-3">
                                <x-forms.label for="revision_date" :label="__('quotations.attributes.revision_date')" required />
                                @if ($isReadonly)
                                    <x-forms.view-field for="revision_date" :value="$plainDate($currentRevision?->revision_date)" dir="ltr" input-class="date-value text-center" />
                                @else
                                    <input class="form-control text-center js-date-picker" id="revision_date" name="revision_date" type="text" value="{{ $dateValue('revision_date', $currentRevision?->revision_date ?? now()) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" required>
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="revision_date"></div>
                            </div>

                            <div class="col-md-9">
                                <x-forms.label for="change_reason" :label="__('quotations.attributes.change_reason')" />
                                @if ($isReadonly)
                                    <x-forms.view-field for="change_reason" :value="$currentRevision?->change_reason" />
                                @else
                                    <input class="form-control" id="change_reason" name="change_reason" type="text" value="{{ old('change_reason', $currentRevision?->change_reason) }}">
                                @endif
                                <div class="invalid-feedback d-block" data-error-for="change_reason"></div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="quotation-lines" role="tabpanel" aria-labelledby="quotation-lines-tab">
                        @unless ($isReadonly)
                            <div class="mb-2 text-end">
                                <button class="btn btn-falcon-default btn-sm js-quotation-add-line" type="button">
                                    <span class="fas fa-plus me-1"></span>{{ __('quotations.actions.add_line') }}
                                </button>
                            </div>
                        @endunless
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0 quotation-lines-table js-quotation-lines">
                                <thead class="bg-200">
                                    <tr>
                                        <th class="quotation-product-cell">{{ __('quotations.attributes.product') }}</th>
                                        <th class="quotation-description-cell">{{ __('quotations.attributes.description') }}</th>
                                        <th>{{ __('quotations.attributes.unit') }}</th>
                                        <th class="text-center">{{ __('quotations.attributes.quantity') }}</th>
                                        <th class="text-center">{{ __('quotations.attributes.unit_price') }}</th>
                                        <th>{{ __('quotations.attributes.discount') }}</th>
                                        <th class="text-center">{{ __('quotations.attributes.tax_rate') }}</th>
                                        <th class="text-center">{{ __('quotations.attributes.total') }}</th>
                                        <th>{{ __('quotations.attributes.requested_date') }}</th>
                                        <th>{{ __('quotations.attributes.packaging') }}</th>
                                        <th>{{ __('quotations.attributes.customer_specification') }}</th>
                                        <th>{{ __('quotations.attributes.warehouse_notes') }}</th>
                                        <th>{{ __('quotations.attributes.production_notes') }}</th>
                                        <th>{{ __('quotations.attributes.line_notes') }}</th>
                                        @unless ($isReadonly)
                                            <th class="text-center">{{ __('common.fields.actions') }}</th>
                                        @endunless
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($lines as $index => $line)
                                        <tr class="js-quotation-line" data-index="{{ $index }}">
                                            <td>
                                                @if ($isReadonly)
                                                    <div class="form-control-plaintext">{{ $line['product_label'] ?? __('common.empty_value') }}</div>
                                                @else
                                                    <select class="form-select js-select2-ajax js-quotation-product" name="lines[{{ $index }}][product_doc_num]" data-url="{{ route('admin.sales.select2.quotation-products') }}" data-placeholder="{{ __('quotations.placeholders.product') }}" data-allow-clear="true">
                                                        @if (! empty($line['product_doc_num']))
                                                            <option value="{{ $line['product_doc_num'] }}" data-unit-doc-num="{{ $line['unit_doc_num'] ?? '' }}" data-unit-label="{{ $line['unit_label'] ?? '' }}" selected>{{ $line['product_label'] ?? $line['product_doc_num'] }}</option>
                                                        @endif
                                                    </select>
                                                    <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.product_doc_num"></div>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($isReadonly)
                                                    <div class="form-control-plaintext">{{ $line['description'] ?? __('common.empty_value') }}</div>
                                                @else
                                                    <textarea class="form-control" name="lines[{{ $index }}][description]" rows="1">{{ $line['description'] ?? '' }}</textarea>
                                                    <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.description"></div>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($isReadonly)
                                                    <div class="form-control-plaintext">{{ $line['unit_label'] ?? __('common.empty_value') }}</div>
                                                @else
                                                    <select class="form-select js-select2-ajax js-quotation-unit" name="lines[{{ $index }}][unit_doc_num]" data-url="{{ route('admin.select2.item-units') }}" data-placeholder="{{ __('quotations.placeholders.unit') }}" data-allow-clear="true">
                                                        @if (! empty($line['unit_doc_num']))
                                                            <option value="{{ $line['unit_doc_num'] }}" selected>{{ $line['unit_label'] ?? $line['unit_doc_num'] }}</option>
                                                        @endif
                                                    </select>
                                                    <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.unit_doc_num"></div>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($isReadonly)
                                                    <div class="form-control-plaintext text-center" dir="ltr">{{ $numbers->format($line['quantity'] ?? 0) }}</div>
                                                @else
                                                    <x-forms.numeric-input class="text-center js-quotation-calc" :name="'lines['.$index.'][quantity]'" :value="$line['quantity'] ?? ''" :scale="4" min="0" step="0.0001" />
                                                    <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.quantity"></div>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($isReadonly)
                                                    <div class="form-control-plaintext text-center" dir="ltr">{{ $numbers->format($line['unit_price'] ?? 0) }}</div>
                                                @else
                                                    <x-forms.numeric-input class="text-center js-quotation-calc" :name="'lines['.$index.'][unit_price]'" :value="$line['unit_price'] ?? ''" :scale="4" min="0" step="0.0001" />
                                                    <div class="invalid-feedback d-block" data-error-for="lines.{{ $index }}.unit_price"></div>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($isReadonly)
                                                    <div class="form-control-plaintext">{{ $line['discount_type'] ? __('quotations.discount_types.'.$line['discount_type']).' '.$numbers->format($line['discount_value']) : __('common.empty_value') }}</div>
                                                @else
                                                    <div class="input-group input-group-sm">
                                                        <select class="form-select js-quotation-calc" name="lines[{{ $index }}][discount_type]">
                                                            <option value="">{{ __('quotations.discount_types.none') }}</option>
                                                            <option value="fixed" @selected(($line['discount_type'] ?? null) === 'fixed')>{{ __('quotations.discount_types.fixed') }}</option>
                                                            <option value="percentage" @selected(($line['discount_type'] ?? null) === 'percentage')>{{ __('quotations.discount_types.percentage') }}</option>
                                                        </select>
                                                        <x-forms.numeric-input class="text-center js-quotation-calc" :name="'lines['.$index.'][discount_value]'" :value="$line['discount_value'] ?? 0" :scale="4" min="0" step="0.0001" />
                                                    </div>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($isReadonly)
                                                    <div class="form-control-plaintext text-center" dir="ltr">{{ $numbers->format($line['tax_rate'] ?? 0) }}</div>
                                                @else
                                                    <x-forms.numeric-input class="text-center js-quotation-calc" :name="'lines['.$index.'][tax_rate]'" :value="$line['tax_rate'] ?? 0" :scale="4" min="0" max="100" step="0.0001" />
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <span class="js-quotation-line-total" dir="ltr">{{ $numbers->format($line['line_total'] ?? 0) }}</span>
                                            </td>
                                            <td>
                                                @if ($isReadonly)
                                                    <div class="form-control-plaintext">{{ $line['requested_date'] ?: __('common.empty_value') }}</div>
                                                @else
                                                    <input class="form-control js-date-picker" name="lines[{{ $index }}][requested_date]" value="{{ $line['requested_date'] ?? '' }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off">
                                                @endif
                                            </td>
                                            <td>
                                                @if ($isReadonly)<div class="form-control-plaintext">{{ $line['specifications']['packaging'] ?? __('common.empty_value') }}</div>
                                                @else<input class="form-control" name="lines[{{ $index }}][specifications][packaging]" value="{{ $line['specifications']['packaging'] ?? '' }}">@endif
                                            </td>
                                            <td>
                                                @if ($isReadonly)<div class="form-control-plaintext">{{ $line['specifications']['customer_specification'] ?? __('common.empty_value') }}</div>
                                                @else<input class="form-control" name="lines[{{ $index }}][specifications][customer_specification]" value="{{ $line['specifications']['customer_specification'] ?? '' }}">@endif
                                            </td>
                                            <td>
                                                @if ($isReadonly)<div class="form-control-plaintext">{{ $line['warehouse_notes'] ?? __('common.empty_value') }}</div>
                                                @else<input class="form-control" name="lines[{{ $index }}][warehouse_notes]" value="{{ $line['warehouse_notes'] ?? '' }}">@endif
                                            </td>
                                            <td>
                                                @if ($isReadonly)<div class="form-control-plaintext">{{ $line['production_notes'] ?? __('common.empty_value') }}</div>
                                                @else<input class="form-control" name="lines[{{ $index }}][production_notes]" value="{{ $line['production_notes'] ?? '' }}">@endif
                                            </td>
                                            <td>
                                                @if ($isReadonly)
                                                    <div class="form-control-plaintext">{{ $line['notes'] ?? __('common.empty_value') }}</div>
                                                @else
                                                    <input class="form-control" name="lines[{{ $index }}][notes]" type="text" value="{{ $line['notes'] ?? '' }}">
                                                @endif
                                            </td>
                                            @unless ($isReadonly)
                                                <td class="text-center">
                                                    <button class="btn btn-link text-600 p-0 me-2 js-quotation-duplicate-line" type="button"><span class="fas fa-copy"></span></button>
                                                    <button class="btn btn-link text-danger p-0 js-quotation-remove-line" type="button"><span class="fas fa-trash-alt"></span></button>
                                                </td>
                                            @endunless
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="invalid-feedback d-block" data-error-for="lines"></div>
                        <div class="mt-3 ms-auto quotation-total-box">
                            <div class="row g-2 align-items-center">
                                <label class="col-5 col-form-label">{{ __('quotations.attributes.revision_discount') }}</label>
                                <div class="col-7">
                                    @if ($isReadonly)
                                        <div class="form-control-plaintext" dir="ltr">{{ $currentRevision?->discount_type ? __('quotations.discount_types.'.$currentRevision->discount_type).' '.$numbers->format($currentRevision->discount_value) : __('common.empty_value') }}</div>
                                    @else
                                        <div class="input-group input-group-sm">
                                            <select class="form-select js-quotation-calc" name="discount_type">
                                                <option value="">{{ __('quotations.discount_types.none') }}</option>
                                                <option value="fixed" @selected($discountType === 'fixed')>{{ __('quotations.discount_types.fixed') }}</option>
                                                <option value="percentage" @selected($discountType === 'percentage')>{{ __('quotations.discount_types.percentage') }}</option>
                                            </select>
                                            <x-forms.numeric-input class="text-center js-quotation-calc" name="discount_value" :value="old('discount_value', $currentRevision?->discount_value ?? 0)" :scale="4" min="0" step="0.0001" />
                                        </div>
                                    @endif
                                </div>
                                <div class="col-5 text-700">{{ __('quotations.attributes.subtotal') }}</div>
                                <div class="col-7 text-end js-quotation-subtotal" dir="ltr">{{ $numbers->format($currentRevision?->subtotal ?? 0) }}</div>
                                <div class="col-5 text-700">{{ __('quotations.attributes.discount_amount') }}</div>
                                <div class="col-7 text-end js-quotation-discount" dir="ltr">{{ $numbers->format($currentRevision?->discount_amount ?? 0) }}</div>
                                <div class="col-5 text-700">{{ __('quotations.attributes.tax_amount') }}</div>
                                <div class="col-7 text-end js-quotation-tax" dir="ltr">{{ $numbers->format($currentRevision?->tax_amount ?? 0) }}</div>
                                <div class="col-5 fw-bold">{{ __('quotations.attributes.total') }}</div>
                                <div class="col-7 text-end fw-bold js-quotation-total" dir="ltr">{{ $numbers->format($currentRevision?->total ?? 0) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="quotation-payments" role="tabpanel" aria-labelledby="quotation-payments-tab">
                        @unless ($isReadonly)
                            <div class="mb-2 text-end">
                                <button class="btn btn-falcon-default btn-sm js-quotation-add-milestone" type="button">
                                    <span class="fas fa-plus me-1"></span>{{ __('quotations.actions.add_milestone') }}
                                </button>
                            </div>
                        @endunless
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0 quotation-simple-grid js-quotation-milestones">
                                <thead class="bg-200">
                                    <tr>
                                        <th>{{ __('quotations.attributes.milestone_title') }}</th>
                                        <th>{{ __('quotations.attributes.description') }}</th>
                                        <th>{{ __('quotations.attributes.percentage') }}</th>
                                        <th>{{ __('quotations.attributes.amount') }}</th>
                                        <th>{{ __('quotations.attributes.due_type') }}</th>
                                        <th>{{ __('quotations.attributes.due_date') }}</th>
                                        <th>{{ __('quotations.attributes.notes') }}</th>
                                        @unless ($isReadonly)<th class="text-center">{{ __('common.fields.actions') }}</th>@endunless
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($paymentMilestones as $index => $row)
                                        <tr class="js-quotation-milestone" data-index="{{ $index }}">
                                            @include('modules.sales.quotations.partials.milestone-row', ['index' => $index, 'row' => $row, 'isReadonly' => $isReadonly, 'dates' => $dates])
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="quotation-execution" role="tabpanel" aria-labelledby="quotation-execution-tab">
                        @unless ($isReadonly)
                            <div class="mb-2 text-end">
                                <button class="btn btn-falcon-default btn-sm js-quotation-add-schedule" type="button">
                                    <span class="fas fa-plus me-1"></span>{{ __('quotations.actions.add_schedule_line') }}
                                </button>
                            </div>
                        @endunless
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0 quotation-simple-grid js-quotation-schedule">
                                <thead class="bg-200">
                                    <tr>
                                        <th>{{ __('quotations.attributes.phase_name') }}</th>
                                        <th>{{ __('quotations.attributes.description') }}</th>
                                        <th>{{ __('quotations.attributes.start_date') }}</th>
                                        <th>{{ __('quotations.attributes.end_date') }}</th>
                                        <th>{{ __('quotations.attributes.duration_days') }}</th>
                                        <th>{{ __('quotations.attributes.responsibility') }}</th>
                                        <th>{{ __('quotations.attributes.notes') }}</th>
                                        @unless ($isReadonly)<th class="text-center">{{ __('common.fields.actions') }}</th>@endunless
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($executionScheduleLines as $index => $row)
                                        <tr class="js-quotation-schedule-line" data-index="{{ $index }}">
                                            @include('modules.sales.quotations.partials.schedule-row', ['index' => $index, 'row' => $row, 'isReadonly' => $isReadonly, 'dates' => $dates])
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="quotation-terms" role="tabpanel" aria-labelledby="quotation-terms-tab">
                        <div class="row g-3">
                            @foreach ([
                                'terms' => 'terms_snapshot',
                                'payment_terms' => 'payment_terms_snapshot',
                                'execution_terms' => 'execution_terms_snapshot',
                                'warranty_terms' => 'warranty_terms_snapshot',
                                'delivery_terms' => 'delivery_terms_snapshot',
                                'technical_notes' => 'technical_notes_snapshot',
                                'notes' => 'notes_snapshot',
                            ] as $field => $snapshot)
                                <div class="col-12">
                                    <x-forms.label :for="$field" :label="__('quotations.attributes.'.$field)" />
                                    @if ($isReadonly)
                                        <div id="{{ $field }}" class="px-3 py-2 border form-control-plaintext rounded-2">{!! $richDisplay($currentRevision?->{$snapshot}) !!}</div>
                                    @else
                                        <textarea class="form-control js-quotation-rich-editor" id="{{ $field }}" name="{{ $field }}" rows="5" data-direction="{{ $editorDirection }}">{{ $richValue($field, $currentRevision?->{$snapshot}) }}</textarea>
                                    @endif
                                    <div class="invalid-feedback d-block" data-error-for="{{ $field }}"></div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="tab-pane fade" id="quotation-attachments" role="tabpanel" aria-labelledby="quotation-attachments-tab">
                        @if (! $isReadonly)
                            @can('quotations.attachments.manage')
                                @can('file_manager.view')
                                    <div class="p-3 mb-3 border rounded-2 bg-body-tertiary">
                                        <div class="flex-wrap gap-2 d-flex align-items-center justify-content-between">
                                            <div>
                                                <div class="fw-semibold">{{ __('quotations.actions.add_attachment') }}</div>
                                                <div class="small text-600">{{ __('quotations.help.attachments') }}</div>
                                            </div>
                                            <button type="button"
                                                class="btn btn-falcon-primary btn-sm js-quotation-attachment-picker-trigger"
                                                data-file-picker
                                                data-picker-accept="document"
                                                data-picker-max="1"
                                                data-picker-title="{{ __('quotations.actions.choose_files') }}"
                                                data-picker-collection="quotation_attachments"
                                                data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                                data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                                <span class="fas fa-paperclip me-1"></span>{{ __('quotations.actions.choose_files') }}
                                            </button>
                                        </div>
                                        <div id="quotation_attachment_file_inputs"></div>
                                        <div class="invalid-feedback d-block" data-error-for="attachment_file_doc_nums"></div>
                                    </div>
                                    <div class="mb-3 table-responsive d-none" id="quotation-selected-attachments-wrap">
                                        <table class="table mb-0 align-middle table-sm">
                                            <thead class="bg-100 text-900">
                                                <tr>
                                                    <th>{{ __('quotations.attributes.attachments') }}</th>
                                                    <th>{{ __('quotations.attributes.file_size') }}</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody id="quotation-selected-attachments"></tbody>
                                        </table>
                                    </div>
                                @else
                                    <div class="alert alert-subtle-warning">{{ __('quotations.messages.file_manager_required') }}</div>
                                @endcan
                            @endcan
                        @endif

                        @if (($record?->attachments?->count() ?? 0) > 0)
                            <div class="table-responsive">
                                <table class="table mb-0 align-middle table-sm">
                                    <thead class="bg-100 text-900">
                                        <tr>
                                            <th>{{ __('quotations.attributes.attachments') }}</th>
                                            <th>{{ __('quotations.attributes.file_size') }}</th>
                                            <th>{{ __('quotations.attributes.uploaded_by') }}</th>
                                            <th>{{ __('quotations.attributes.uploaded_at') }}</th>
                                            <th class="text-end"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($record->attachments as $attachment)
                                            <tr data-attachment-uuid="{{ $attachment->public_uuid }}">
                                                <td>
                                                    <div class="fw-semibold text-truncate" title="{{ $attachment->original_name }}">
                                                        <span class="fas fa-paperclip text-500 me-1"></span>{{ $attachment->original_name }}
                                                    </div>
                                                    <div class="text-600 fs-11">{{ $attachment->archiveFile?->doc_num ?? $attachment->mime_type }}</div>
                                                </td>
                                                <td dir="ltr">{{ $formatFileSize((int) $attachment->size) }}</td>
                                                <td>{{ $attachment->uploadedBy ? trim(implode(' / ', array_filter([$attachment->uploadedBy->name, $attachment->uploadedBy->doc_num]))) : __('common.empty_value') }}</td>
                                                <td>{{ $settings->formatDateTime($attachment->created_at, '') }}</td>
                                                <td class="text-end white-space-nowrap">
                                                    @if ($attachment->archiveFile)
                                                        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.file-manager.files.preview', $attachment->archiveFile->doc_num) }}" target="_blank" rel="noopener">
                                                            <span class="fas fa-external-link-alt"></span>
                                                        </a>
                                                        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.file-manager.files.download', $attachment->archiveFile->doc_num) }}">
                                                            <span class="fas fa-download"></span>
                                                        </a>
                                                    @endif
                                                    @if (! $isReadonly)
                                                        @can('quotations.attachments.manage')
                                                            <button class="btn btn-falcon-danger btn-sm js-delete-quotation-attachment" type="button" data-url="{{ route('admin.sales.quotations.attachments.destroy', $attachment->public_uuid) }}">
                                                                <span class="fas fa-trash-alt"></span>
                                                            </button>
                                                        @endcan
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-600">{{ __('quotations.empty.attachments') }}</div>
                        @endif
                    </div>

                    <div class="tab-pane fade" id="quotation-revisions" role="tabpanel" aria-labelledby="quotation-revisions-tab">
                        @if ($record && $record->revisions->isNotEmpty())
                            <div class="table-responsive">
                                <table class="table table-sm table-hover align-middle mb-0">
                                    <thead class="bg-200">
                                        <tr>
                                            <th>{{ __('quotations.attributes.revision_number') }}</th>
                                            <th>{{ __('quotations.attributes.revision_code') }}</th>
                                            <th>{{ __('quotations.attributes.revision_date') }}</th>
                                            <th>{{ __('quotations.attributes.status') }}</th>
                                            <th>{{ __('quotations.attributes.total') }}</th>
                                            <th>{{ __('quotations.attributes.created_by') }}</th>
                                            <th>{{ __('quotations.attributes.change_reason') }}</th>
                                            <th class="text-end"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($record->revisions as $history)
                                            <tr>
                                                <td dir="ltr">{{ $history->revision_number }}</td>
                                                <td dir="ltr" class="fw-semibold">{{ $history->revision_code }}</td>
                                                <td dir="ltr">{{ $plainDate($history->revision_date) }}</td>
                                                <td>@include('modules.sales.quotations.partials.status', ['status' => $history->status])</td>
                                                <td dir="ltr">{{ $numbers->format($history->total) }}</td>
                                                <td>{{ $history->createdBy ? trim(implode(' / ', array_filter([$history->createdBy->name, $history->createdBy->doc_num]))) : __('common.empty_value') }}</td>
                                                <td>{{ $history->change_reason ?: __('common.empty_value') }}</td>
                                                <td class="text-end white-space-nowrap">
                                                    @can('quotations.revisions.view')
                                                        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.quotations.revisions.show', [$record->doc_num, $history->revision_code]) }}">
                                                            <span class="fas fa-eye"></span>
                                                        </a>
                                                    @endcan
                                                    @can('quotations.print')
                                                        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.quotations.revisions.print', [$record, $history]) }}" target="_blank">
                                                            <span class="fas fa-print"></span>
                                                        </a>
                                                    @endcan
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <div class="text-600">{{ __('quotations.empty.revisions') }}</div>
                        @endif

                        @if ($isView || (! $isCreateLike && $record))
                            <x-audit-fields-row
                                :metadata="$metadata"
                                :show-deleted="$isView && ($record?->trashed() ?? false)"
                                :show-restored="$isView && ! ($record?->trashed() ?? false) && (($record?->restored_at ?? null) || ($record?->restored_by ?? null))"
                            />
                        @endif
                    </div>
                </div>
            </div>
            <div class="card-footer">
                @include('modules.sales.quotations.partials.form-actions', [
                    'canEditRecord' => ! ($isRevisionLocked ?? false),
                    'canDeleteRecord' => true,
                ])
            </div>
        </div>
    </form>

    @unless ($isReadonly)
        <template id="quotation-line-template">
            <tr class="js-quotation-line" data-index="__INDEX__">
                @include('modules.sales.quotations.partials.line-template')
            </tr>
        </template>
        <template id="quotation-milestone-template">
            <tr class="js-quotation-milestone" data-index="__INDEX__">
                @include('modules.sales.quotations.partials.milestone-row', ['index' => '__INDEX__', 'row' => [], 'isReadonly' => false, 'dates' => $dates])
            </tr>
        </template>
        <template id="quotation-schedule-template">
            <tr class="js-quotation-schedule-line" data-index="__INDEX__">
                @include('modules.sales.quotations.partials.schedule-row', ['index' => '__INDEX__', 'row' => [], 'isReadonly' => false, 'dates' => $dates])
            </tr>
        </template>
        @can('quotations.attachments.manage')
            @can('file_manager.view')
                <x-file-picker-modal />
            @endcan
        @endcan
    @endunless
@endsection

@push('scripts')
    <script>
        window.quotationMessages = @json(__('quotations.js'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ $asset->url('vendors/summernote/summernote-bs5.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Core/select2-ajax.js') }}"></script>
    @unless ($isReadonly)
        @can('quotations.attachments.manage')
            @can('file_manager.view')
                <script src="{{ $asset->url('assets/js/modules/Core/file-picker.js') }}"></script>
            @endcan
        @endcan
    @endunless
    <script src="{{ asset('assets/js/modules/Sales/quotations.js') }}"></script>
@endpush
