@extends('layouts.app')

@section('title', __('Goods Receipt Note'))

@section('content')
    @php
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $draft = $draft ?? null;
        $sourceInspection = $sourceInspection ?? null;
        $sourceDocument = $sourceInspection?->doc_num ?? $record->doc_num;
        $formAction = $draft
            ? route('admin.purchases.goods-receipt-notes.update', $draft->doc_num)
            : route('admin.purchases.goods-receipt-notes.store', $sourceDocument);
    @endphp

    <form method="POST" action="{{ $formAction }}">
        @csrf
        <x-forms.line-item-cards />
        @if($draft)
            @method('PUT')
        @endif

        @if($sourceInspection)
            <div class="alert alert-success d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div>
                    <span class="fas fa-check-circle me-1"></span>
                    {{ __('This receipt is based on accepted quantities from purchase inspection :document.', ['document' => $sourceInspection->doc_num]) }}
                </div>
                <a class="alert-link" href="{{ route('admin.purchases.goods-receipt-inspection.show', $sourceInspection) }}">{{ __('View inspection') }}</a>
            </div>
        @endif

        <div class="card mb-3">
            @include('modules.purchases.procurement.partials.form-toolbar', [
                'toolbarTitle' => $sourceInspection
                    ? __('Create Receipt from Inspection :document', ['document' => $sourceInspection->doc_num])
                    : __('Receive Purchase Order :document', ['document' => $record->doc_num]),
                'toolbarPermission' => 'purchases.goods_receipt_notes',
                'toolbarRoute' => 'goods-receipt-notes',
            ])
            <div class="card-body">
                @if($errors->any())
                    <div class="alert alert-danger">{{ $errors->first() }}</div>
                @endif
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="document_date">{{ __('Receipt date') }}</label>
                        <input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" id="document_date" name="document_date" value="{{ old('document_date', $dates->formatDate($draft?->document_date ?? now())) }}" required>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="supplier_delivery_note">{{ __('Supplier delivery note') }}</label>
                        <input class="form-control" id="supplier_delivery_note" name="supplier_delivery_note" value="{{ old('supplier_delivery_note', $draft?->supplier_delivery_note) }}">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label" for="supplier_delivery_date">{{ __('Supplier delivery date') }}</label>
                        <input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" id="supplier_delivery_date" name="supplier_delivery_date" value="{{ old('supplier_delivery_date', $dates->formatDate($draft?->reference_date, '')) }}">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">{{ __('Destination') }}</label>
                        <input class="form-control" value="{{ $record->branchStore?->name }}" readonly>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="notes">{{ __('Notes') }}</label>
                        <input class="form-control" id="notes" name="notes" value="{{ old('notes', $draft?->notes) }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body p-0">
                <div class="table-responsive procurement-lines-scroll">
                    @if($sourceInspection)
                        <table class="table table-sm align-middle mb-0 procurement-lines-table">
                            <thead class="bg-100">
                                <tr>
                                    <th>{{ __('Item') }}</th>
                                    <th>{{ __('Unit') }}</th>
                                    <th class="text-end">{{ __('Inspected') }}</th>
                                    <th class="text-end">{{ __('Accepted for receipt') }}</th>
                                    <th>{{ __('Supplier lot') }}</th>
                                    <th>{{ __('Expiry date') }}</th>
                                    <th>{{ __('Notes') }}</th>
                                    <th>{{ __('Attachments') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($sourceInspection->lines->filter(fn ($line) => (float) $line->accepted_quantity > 0)->values() as $index => $line)
                                    <tr>
                                        <td>
                                            <div class="fw-semibold">{{ $line->product?->doc_num }} / {{ $line->product?->name }}</div>
                                            <input type="hidden" name="lines[{{ $index }}][inspection_line_public_id]" value="{{ $line->public_id }}">
                                            @if($line->supplyOrderLine)
                                                <input type="hidden" name="lines[{{ $index }}][supply_order_line_public_id]" value="{{ $line->supplyOrderLine->public_id }}">
                                            @else
                                                <input type="hidden" name="lines[{{ $index }}][purchase_order_line_public_id]" value="{{ $line->purchaseOrderLine?->public_id }}">
                                            @endif
                                            <input type="hidden" name="lines[{{ $index }}][delivered_quantity]" value="{{ $line->accepted_quantity }}">
                                        </td>
                                        <td>{{ $line->unit?->name }}</td>
                                        <td class="text-end" dir="ltr">{{ $numbers->format($line->inspected_quantity) }}</td>
                                        <td class="text-end fw-semibold" dir="ltr">{{ $numbers->format($line->accepted_quantity) }}</td>
                                        <td>
                                            {{ $line->supplier_lot_number ?: '—' }}
                                            <input type="hidden" name="lines[{{ $index }}][supplier_lot_number]" value="{{ $line->supplier_lot_number }}">
                                            <input type="hidden" name="lines[{{ $index }}][manufacture_date]" value="{{ $line->manufacture_date?->toDateString() }}">
                                            <input type="hidden" name="lines[{{ $index }}][expiry_date]" value="{{ $line->expiry_date?->toDateString() }}">
                                        </td>
                                        <td>{{ $dates->formatDate($line->expiry_date, '—') }}</td>
                                        <td><input class="form-control" name="lines[{{ $index }}][notes]" value="{{ old('lines.'.$index.'.notes', $line->notes) }}"></td>
                                        <td>@include('modules.purchases.procurement.line-attachments', ['attachmentLine' => null, 'attachmentCompanyId' => $sourceInspection->company_id, 'index' => $index])</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @else
                        <table class="table table-sm align-middle mb-0 procurement-lines-table">
                            <thead class="bg-100">
                                <tr>
                                    <th>{{ __('Item') }}</th>
                                    <th>{{ __('Unit') }}</th>
                                    <th>{{ __('Ordered') }}</th>
                                    <th>{{ __('Previously received') }}</th>
                                    <th class="text-end">{{ __('Remaining') }}</th>
                                    <th>{{ __('Schedule') }}</th>
                                    <th>{{ __('Delivered') }}</th>
                                    <th>{{ __('Supplier lot') }}</th>
                                    <th>{{ __('Manufacture date') }}</th>
                                    <th>{{ __('Expiry date') }}</th>
                                    <th>{{ __('Notes') }}</th>
                                    <th>{{ __('Attachments') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($record->lines->reject(fn ($line) => $line->product?->isService() || ($line->quantityProgress()['remaining'] <= 0 && ! $draft?->lines->contains('purchase_order_line_id', $line->getKey()))) as $index => $line)
                                    @php($draftLine = $draft?->lines->firstWhere('purchase_order_line_id', $line->getKey()))
                                    <tr>
                                        <td>{{ $line->product?->doc_num }} / {{ $line->product?->name }}</td>
                                        <td>{{ $line->unit?->name }}</td>
                                        <td dir="ltr">{{ $numbers->format($line->ordered_quantity) }}</td>
                                        <td dir="ltr">{{ $numbers->format($line->quantityProgress()['received']) }}</td>
                                        <td class="text-end" dir="ltr">{{ $numbers->format(max(0, $line->quantityProgress()['remaining'])) }}</td>
                                        <td @if($line->deliverySchedules->isEmpty()) hidden @endif>
                                            <select class="form-select js-select2-local" name="lines[{{ $index }}][delivery_schedule_public_id]">
                                                <option value="">{{ __('Unscheduled') }}</option>
                                                @foreach($line->deliverySchedules->whereNotIn('status', ['received', 'cancelled']) as $schedule)
                                                    <option value="{{ $schedule->public_id }}" @selected(old('lines.'.$index.'.delivery_schedule_public_id', $draftLine?->deliverySchedule?->public_id) === $schedule->public_id)>{{ $schedule->scheduled_date?->format('Y-m-d') }} / {{ $numbers->format((float) $schedule->scheduled_quantity - (float) $schedule->received_quantity) }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <input type="hidden" name="lines[{{ $index }}][purchase_order_line_public_id]" value="{{ $line->public_id }}">
                                            <x-forms.numeric-input name="lines[{{ $index }}][delivered_quantity]" :scale="8" min="0" step="0.00000001" :value="old('lines.'.$index.'.delivered_quantity', $draft ? ($draftLine?->delivered_quantity ?? 0) : max(0, $line->quantityProgress()['remaining']))" />
                                        </td>
                                        <td><input class="form-control" name="lines[{{ $index }}][supplier_lot_number]" value="{{ old('lines.'.$index.'.supplier_lot_number', $draftLine?->supplier_lot_number) }}"></td>
                                        <td @if(!$line->product?->tracks_expiry && !$draftLine?->expiry_date) hidden @endif><input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="lines[{{ $index }}][manufacture_date]" value="{{ old('lines.'.$index.'.manufacture_date', $dates->formatDate($draftLine?->manufacture_date, '')) }}"></td>
                                        <td @if(!$line->product?->tracks_expiry && !$draftLine?->expiry_date) hidden @endif><input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="lines[{{ $index }}][expiry_date]" value="{{ old('lines.'.$index.'.expiry_date', $dates->formatDate($draftLine?->expiry_date, '')) }}"></td>
                                        <td><input class="form-control" name="lines[{{ $index }}][notes]" value="{{ old('lines.'.$index.'.notes', $draftLine?->notes) }}"></td>
                                        <td>@include('modules.purchases.procurement.line-attachments', ['attachmentLine' => $draftLine, 'attachmentCompanyId' => $draft?->company_id ?? $record->company_id, 'index' => $index])</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>

        @include('modules.purchases.procurement.attachments', ['attachmentRecord' => $draft ?? null, 'attachmentsReadonly' => false])
    </form>
@endsection
