@extends('layouts.app')

@section('title', __('Purchase Inspection'))

@section('content')
    @php
        $dates = app(\Modules\Core\Services\DateFormatService::class);
        $numbers = app(\Modules\Core\Services\NumericFormatService::class);
        $isSupplyOrder = $record instanceof \Modules\Purchases\Models\SupplyOrder;
        $sourceLines = $record->lines
            ->reject(fn ($line) => $line->product?->isService())
            ->filter(fn ($line) => (float) $line->available_inspection_quantity > 0)
            ->values();
    @endphp

    <form method="POST" action="{{ route('admin.purchases.goods-receipt-inspection.store', $record->doc_num) }}">
        @csrf
        <x-forms.line-item-cards />

        <div class="alert alert-info d-flex gap-2 align-items-start">
            <span class="fas fa-circle-info mt-1"></span>
            <div>
                <div class="fw-semibold">{{ __('Quality inspection happens before warehouse receipt.') }}</div>
                <div class="fs-10">{{ __('Finalizing this document does not move stock. The warehouse can create a goods receipt only for accepted quantities.') }}</div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header py-2 d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div>
                    <h5 class="mb-1">{{ __('Inspect Purchase Source :document', ['document' => $record->doc_num]) }}</h5>
                    <div class="text-600 fs-10">
                        {{ $isSupplyOrder ? __('Supply Order') : __('Purchase Order') }}
                        <span dir="ltr">{{ $record->doc_num }}</span>
                        <span class="mx-1">·</span>{{ $record->supplier?->name }}
                        <span class="mx-1">·</span>{{ $record->branchStore?->name }}
                    </div>
                </div>
                <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.goods-receipt-inspection.choose-source') }}">
                    {{ __('Change source') }}
                </a>
            </div>
            <div class="card-body py-3">
                @if($errors->any())
                    <div class="alert alert-danger">{{ $errors->first() }}</div>
                @endif
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="inspection_at">{{ __('Inspection date/time') }}</label>
                        <input class="form-control" type="datetime-local" id="inspection_at" name="inspection_at" value="{{ old('inspection_at', now()->format('Y-m-d\TH:i')) }}">
                    </div>
                    <div class="col-12 col-md-8">
                        <label class="form-label" for="observations">{{ __('Observations') }}</label>
                        <input class="form-control" id="observations" name="observations" value="{{ old('observations') }}">
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body p-0">
                <div class="table-responsive procurement-lines-scroll">
                    <table class="table table-sm align-middle mb-0 procurement-lines-table">
                        <thead class="bg-100">
                            <tr>
                                <th>{{ __('Item') }}</th>
                                <th>{{ __('Unit') }}</th>
                                <th>{{ __('Delivery schedule') }}</th>
                                <th class="text-end">{{ __('Available to inspect') }}</th>
                                <th>{{ __('Delivered for inspection') }}</th>
                                <th>{{ __('Accepted') }}</th>
                                <th>{{ __('Rejected') }}</th>
                                <th>{{ __('Supplier lot') }}</th>
                                <th>{{ __('Manufacture date') }}</th>
                                <th>{{ __('Expiry date') }}</th>
                                <th>{{ __('Disposition') }}</th>
                                <th>{{ __('Reason / observations') }}</th>
                                <th>{{ __('Attachments') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($sourceLines as $index => $line)
                                @php
                                    $orderLine = $isSupplyOrder ? $line->purchaseOrderLine : $line;
                                    $available = (float) $line->available_inspection_quantity;
                                @endphp
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $line->product?->doc_num }} / {{ $line->product?->name }}</div>
                                        @if($isSupplyOrder)
                                            <input type="hidden" name="lines[{{ $index }}][supply_order_line_public_id]" value="{{ $line->public_id }}">
                                        @else
                                            <input type="hidden" name="lines[{{ $index }}][purchase_order_line_public_id]" value="{{ $line->public_id }}">
                                        @endif
                                    </td>
                                    <td>{{ $line->unit?->name }}</td>
                                    <td>
                                        @if($orderLine?->deliverySchedules?->isNotEmpty())
                                            <select class="form-select js-select2-local" name="lines[{{ $index }}][delivery_schedule_public_id]">
                                                <option value="">{{ __('Unscheduled') }}</option>
                                                @foreach($orderLine->deliverySchedules->whereNotIn('status', ['received', 'cancelled']) as $schedule)
                                                    <option value="{{ $schedule->public_id }}">{{ $schedule->scheduled_date?->format('Y-m-d') }} / {{ $numbers->format((float) $schedule->scheduled_quantity - (float) $schedule->received_quantity) }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <span class="text-500">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($available) }}</td>
                                    <td>
                                        <x-forms.numeric-input name="lines[{{ $index }}][delivered_quantity]" :scale="8" min="0.00000001" :max="$available" step="0.00000001" :value="old('lines.'.$index.'.delivered_quantity', $available)" required />
                                    </td>
                                    <td>
                                        <x-forms.numeric-input name="lines[{{ $index }}][accepted_quantity]" :scale="8" min="0" :max="$available" step="0.00000001" :value="old('lines.'.$index.'.accepted_quantity', $available)" required />
                                    </td>
                                    <td>
                                        <x-forms.numeric-input name="lines[{{ $index }}][rejected_quantity]" :scale="8" min="0" :max="$available" step="0.00000001" :value="old('lines.'.$index.'.rejected_quantity', 0)" required />
                                    </td>
                                    <td><input class="form-control" name="lines[{{ $index }}][supplier_lot_number]" value="{{ old('lines.'.$index.'.supplier_lot_number') }}"></td>
                                    <td @if(!$line->product?->tracks_expiry) hidden @endif>
                                        <input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="lines[{{ $index }}][manufacture_date]" value="{{ old('lines.'.$index.'.manufacture_date') }}">
                                    </td>
                                    <td @if(!$line->product?->tracks_expiry) hidden @endif>
                                        <input class="form-control js-date-picker" type="text" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" dir="ltr" name="lines[{{ $index }}][expiry_date]" value="{{ old('lines.'.$index.'.expiry_date') }}">
                                    </td>
                                    <td>
                                        <select class="form-select" name="lines[{{ $index }}][disposition]">
                                            <option value="quarantine">{{ __('Quarantine') }}</option>
                                            <option value="return_supplier">{{ __('Return to supplier') }}</option>
                                            <option value="reinspect">{{ __('Reinspect') }}</option>
                                            <option value="conditional_acceptance">{{ __('Conditional acceptance') }}</option>
                                        </select>
                                    </td>
                                    <td><input class="form-control" name="lines[{{ $index }}][reason]" value="{{ old('lines.'.$index.'.reason') }}"></td>
                                    <td>@include('modules.purchases.procurement.line-attachments', ['attachmentLine' => null, 'attachmentCompanyId' => $record->company_id, 'index' => $index])</td>
                                </tr>
                            @empty
                                <tr><td colspan="13" class="text-center text-500 py-5">{{ __('No quantities are available for inspection.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        @include('modules.purchases.procurement.attachments', [
            'attachmentRecord' => null,
            'attachmentsReadonly' => false,
            'attachmentCollection' => \Modules\Purchases\Models\GoodsReceiptInspection::AttachmentCollection,
        ])

        <div class="d-flex justify-content-end">
            <button class="btn btn-primary" @disabled($sourceLines->isEmpty())>{{ __('procurement.ui.finalize_quality_inspection') }}</button>
        </div>
    </form>
@endsection

@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Purchases/procurement-cycle.js') }}"></script>
@endpush
