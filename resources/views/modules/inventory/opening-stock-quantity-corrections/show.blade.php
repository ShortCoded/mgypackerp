@extends('layouts.app')
@section('title', __('opening_stock_quantity_correction.title').' — '.$record->doc_num)
@section('content')
@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $safeOld = static function (string $key, mixed $default = null): mixed {
        $value = old($key, $default);

        return is_scalar($value) || $value === null ? $value : $default;
    };
    $pending = $proposals->firstWhere('status', \Modules\Inventory\Models\OpeningStockQuantityCorrection::StatusPending);
    $correctedQuantities = $record->lines->mapWithKeys(fn ($line) => [$line->id => (string) $line->quantity])->all();
    foreach ($proposals->where('status', \Modules\Inventory\Models\OpeningStockQuantityCorrection::StatusApproved) as $approved) {
        foreach ($approved->plan['lines'] ?? [] as $entry) {
            $correctedQuantities[$entry['line_id']] = bcadd($correctedQuantities[$entry['line_id']], (string) $entry['delta_quantity'], 4);
        }
    }
@endphp
<div class="card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between gap-2"><h5>{{ __('opening_stock_quantity_correction.title') }} — {{ $record->doc_num }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.opening-stock-quantity-corrections.index') }}">{{ __('Back') }}</a></div>
    <p>{{ __('opening_stock_quantity_correction.help') }}</p><p class="mb-0 text-muted">{{ __('opening_stock_quantity_correction.source') }}: {{ $record->doc_num }} · {{ $dates->formatDate($record->document_date) }}</p>
</div></div>
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if($pending)<p class="alert alert-info">{{ __('opening_stock_quantity_correction.errors.pending') }}</p>
@else
@can('inventory.opening_stock_quantity_corrections.prepare')
<form novalidate method="POST" action="{{ route('admin.inventory.opening-stock-quantity-corrections.prepare', $record) }}" class="card mb-3">
    @csrf
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4"><x-forms.label for="opening-quantity-date" :label="__('opening_stock_quantity_correction.posting_date')" required /><x-forms.date-input id="opening-quantity-date" name="posting_date" :value="$safeOld('posting_date', now()->toDateString())" /></div>
            <div class="col-md-4"><x-forms.label for="opening-quantity-reference" :label="__('opening_stock_quantity_correction.reference')" required /><x-forms.input id="opening-quantity-reference" name="source_reference" :value="$safeOld('source_reference')" /></div>
            <div class="col-md-4"><x-forms.label for="opening-quantity-reason" :label="__('opening_stock_quantity_correction.reason')" required /><x-forms.input id="opening-quantity-reason" name="reason" :value="$safeOld('reason')" /></div>
        </div>
        <p class="text-muted">{{ __('opening_stock_quantity_correction.entry_help') }}</p>
        <x-admin.report.table-card :title="__('opening_stock_quantity_correction.target_quantity')" table-id="opening-quantity-entry" class="mb-3"><thead><tr><th>{{ __('inventory.movements.fields.product') }}</th><th>{{ __('opening_stock_quantity_correction.original_quantity') }}</th><th>{{ __('opening_stock_quantity_correction.current_quantity') }}</th><th>{{ __('opening_stock_quantity_correction.target_quantity') }}</th><th>{{ __('opening_stock_quantity_correction.unit_cost') }}</th><th>{{ __('opening_stock_quantity_correction.selected_layer') }}</th></tr></thead><tbody>
        @foreach($record->lines as $line)
            @php($current = $correctedQuantities[$line->id])
            @php($selectedLayer = $selectedLayers[$line->id] ?? null)
            <tr><td>{{ data_get($line->product_snapshot, 'doc_num') }} — {{ data_get($line->product_snapshot, 'name') }}</td><td dir="ltr" class="text-nowrap">{{ $numbers->format($line->quantity) }}</td><td dir="ltr" class="text-nowrap">{{ $numbers->format($current) }}</td>
                <td style="min-width:10rem"><x-forms.numeric-input :name="'targets['.$line->id.'][target_quantity]'" :value="$safeOld('targets.'.$line->id.'.target_quantity', $current)" :scale="4" :aria-label="__('opening_stock_quantity_correction.target_quantity').' — '.data_get($line->product_snapshot, 'doc_num')" /></td>
                <td style="min-width:10rem"><x-forms.numeric-input :name="'targets['.$line->id.'][unit_cost]'" :value="$safeOld('targets.'.$line->id.'.unit_cost')" :scale="8" :aria-label="__('opening_stock_quantity_correction.unit_cost').' — '.data_get($line->product_snapshot, 'doc_num')" /></td>
                <td style="min-width:18rem">@if($line->product?->tracks_serials)
                    <x-forms.label :for="'opening-quantity-serial-layers-'.$line->id" :label="__('opening_stock_quantity_correction.serial_decrease')" />
                    <x-forms.select :id="'opening-quantity-serial-layers-'.$line->id" :name="'targets['.$line->id.'][serial_receipt_layer_ids][]'" variant="ajax" multiple :url="route('admin.inventory.opening-stock-quantity-corrections.select2.layers', [$record, $line])" data-depends-on="#opening-quantity-date" data-dependent-param="posting_date" :placeholder="__('common.placeholders.select')">@foreach($selectedSerialLayers[$line->id] as $serialLayer)<option selected value="{{ $serialLayer->id }}">{{ $serialLayer->serialIdentity->serial_number }} · {{ $serialLayer->source_doc_num }}</option>@endforeach</x-forms.select>
                    <x-forms.label :for="'opening-quantity-serial-numbers-'.$line->id" :label="__('opening_stock_quantity_correction.serial_increase')" class="mt-2" />
                    <x-forms.textarea :id="'opening-quantity-serial-numbers-'.$line->id" :name="'targets['.$line->id.'][serial_numbers]'" rows="3">{{ implode("\n", array_filter((array) old('targets.'.$line->id.'.serial_numbers', []), 'is_string')) }}</x-forms.textarea>
                    <small>{{ __('opening_stock_quantity_correction.serial_help') }}</small>
                @else<x-forms.select :id="'opening-quantity-layer-'.$line->id" :name="'targets['.$line->id.'][selected_receipt_layer_id]'" variant="ajax" :url="route('admin.inventory.opening-stock-quantity-corrections.select2.layers', [$record, $line])" data-depends-on="#opening-quantity-date" data-dependent-param="posting_date" :placeholder="__('common.placeholders.select')"><option value=""></option>@if($selectedLayer)<option selected value="{{ $selectedLayer->id }}">{{ $selectedLayer->source_doc_num }} · {{ $numbers->format($selectedLayer->remaining_quantity) }}</option>@endif</x-forms.select>@endif</td>
            </tr>
        @endforeach
        </tbody></x-admin.report.table-card>
        <button type="submit" class="btn btn-primary btn-sm">{{ __('opening_stock_quantity_correction.prepare') }}</button>
    </div>
</form>
@endcan
@endif
<h6>{{ __('opening_stock_quantity_correction.history') }}</h6>
@forelse($proposals as $proposal)
<div class="card mb-3"><div class="card-body">
    <h6>{{ __('opening_stock_quantity_correction.statuses.'.$proposal->status) }} · {{ $dates->formatDate($proposal->posting_date) }}</h6>
    <p>{{ __('opening_stock_quantity_correction.prepared_by') }}: {{ $proposal->preparedBy?->name }} · {{ __('opening_stock_quantity_correction.reference') }}: {{ $proposal->source_reference }}</p><p>{{ __('opening_stock_quantity_correction.reason') }}: {{ $proposal->reason }}</p>
    <x-admin.report.table-card :title="__('opening_stock_quantity_correction.history')" :table-id="'opening-quantity-history-'.$proposal->id" class="mb-3"><thead><tr><th>{{ __('inventory.movements.fields.product') }}</th><th>{{ __('opening_stock_quantity_correction.current_quantity') }}</th><th>{{ __('opening_stock_quantity_correction.target_quantity') }}</th><th>{{ __('opening_stock_quantity_correction.delta') }}</th><th>{{ __('opening_stock_quantity_correction.available') }}</th><th>{{ __('opening_stock_quantity_correction.unit_cost') }}</th><th>{{ __('opening_stock_quantity_correction.value') }}</th></tr></thead><tbody>
        @foreach($proposal->plan['lines'] ?? [] as $entry)
        <tr><td>{{ $entry['product_code'] }} — {{ $entry['product_name'] }}<small class="d-block text-muted">{{ __('opening_stock_quantity_correction.debit') }}: {{ __('opening_stock_quantity_correction.accounts.'.$entry['account_labels']['debit']) }} · {{ __('opening_stock_quantity_correction.credit') }}: {{ __('opening_stock_quantity_correction.accounts.'.$entry['account_labels']['credit']) }}</small></td><td dir="ltr">{{ $numbers->format($entry['current_corrected_quantity']) }}</td><td dir="ltr">{{ $numbers->format($entry['target_quantity']) }}</td><td dir="ltr">{{ $numbers->format($entry['delta_quantity']) }}</td><td dir="ltr">{{ $numbers->format($entry['current_available']) }}</td><td dir="ltr">{{ $entry['unit_cost'] === null ? '—' : $numbers->format($entry['unit_cost'], 8) }}</td><td dir="ltr">{{ $entry['value'] === null ? __('opening_stock_quantity_correction.allocator_value') : $numbers->format($entry['value'], 8) }}</td></tr>
        @endforeach
    </tbody></x-admin.report.table-card>
    <x-admin.report.table-card :title="__('opening_stock_quantity_correction.accounting_review')" :table-id="'opening-quantity-accounting-'.$proposal->id" class="mb-3"><thead><tr><th>{{ __('inventory.movements.fields.product') }}</th><th>{{ __('opening_stock_quantity_correction.debit') }}</th><th>{{ __('opening_stock_quantity_correction.credit') }}</th><th>{{ __('opening_stock_quantity_correction.value') }}</th><th>{{ __('opening_stock_quantity_correction.gl_amount') }}</th><th>{{ __('opening_stock_quantity_correction.rounding_difference') }}</th></tr></thead><tbody>
    @foreach($proposal->plan['lines'] ?? [] as $entry)
        @foreach(!empty($entry['serial_units']) ? $entry['serial_units'] : [$entry] as $postingUnit)
        @php($contract = $postingUnit['accounting_contract'] ?? null)
        @if($contract)
        <tr><td>{{ $entry['product_code'] }}@if(filled($postingUnit['serial_number'] ?? null))<small dir="ltr" class="d-block">{{ $postingUnit['serial_number'] }}</small>@endif</td>
            @foreach(['debit', 'credit'] as $side)
                @php($account = $contract[$side.'_account'])
                <td><span dir="ltr">{{ $account['account_code'] }}</span> — {{ app()->getLocale() === 'en' && filled($account['name_en']) ? $account['name_en'] : $account['name'] }}</td>
            @endforeach
            <td dir="ltr" class="text-nowrap">{{ $numbers->format($contract['exact_total_cost'], 8) }}</td><td dir="ltr" class="text-nowrap">{{ $numbers->format($contract['booked_amount'], 4) }}</td><td dir="ltr" class="text-nowrap">{{ $numbers->format($contract['rounding_difference'], 8) }}</td>
        </tr>
        @else<tr><td colspan="6">{{ __('opening_stock_quantity_correction.review_again') }}</td></tr>@endif
        @endforeach
    @endforeach
    </tbody></x-admin.report.table-card>
    @if($proposal->source_snapshot['source_period_closed'] ?? false)<p class="text-muted">{{ __('opening_stock_quantity_correction.closed_source') }}</p>@endif
    @foreach($proposal->document_links ?? [] as $link)
        <p>{{ __('opening_stock_quantity_correction.movement') }}:
        @can('inventory.documents.view')<a href="{{ route('admin.inventory.documents.show', $link['doc_num']) }}">{{ $link['doc_num'] }}</a>@else{{ $link['doc_num'] }}@endcan
        · {{ __('inventory.movements.types.'.$link['document_type']) }}</p>
    @endforeach
    @if($proposal->approvedBy)<p>{{ __('opening_stock_quantity_correction.approved_by') }}: {{ $proposal->approvedBy->name }} · {{ $proposal->approval_reference }}</p>@endif
    @if($proposal->rejection_reason)<p>{{ __('opening_stock_quantity_correction.rejection_reason') }}: {{ $proposal->rejection_reason }}</p>@endif
    @if($proposal->status === \Modules\Inventory\Models\OpeningStockQuantityCorrection::StatusPending)
    @can('inventory.opening_stock_quantity_corrections.approve')
        @if((int) $proposal->prepared_by !== (int) auth()->id())
        <form novalidate method="POST" action="{{ route('admin.inventory.opening-stock-quantity-corrections.approve', [$record, $proposal]) }}" class="row g-2 mb-3">
            @csrf
            <div class="col-md-8"><x-forms.label :for="'opening-quantity-approval-'.$proposal->id" :label="__('opening_stock_quantity_correction.approval_reference')" required /><x-forms.input :id="'opening-quantity-approval-'.$proposal->id" name="approval_reference" :value="$safeOld('approval_reference')" /></div>
            <div class="col-md-4 d-flex align-items-end"><button type="submit" class="btn btn-primary btn-sm">{{ __('opening_stock_quantity_correction.approve') }}</button></div>
        </form>
        @else<p class="text-muted">{{ __('opening_stock_quantity_correction.independent') }}</p>@endif
        <form novalidate method="POST" action="{{ route('admin.inventory.opening-stock-quantity-corrections.reject', [$record, $proposal]) }}" class="row g-2">
            @csrf
            <div class="col-md-8"><x-forms.label :for="'opening-quantity-reject-'.$proposal->id" :label="__('opening_stock_quantity_correction.rejection_reason')" required /><x-forms.input :id="'opening-quantity-reject-'.$proposal->id" name="reason" :value="$safeOld('reason')" /></div>
            <div class="col-md-4 d-flex align-items-end"><button type="submit" class="btn btn-outline-danger btn-sm">{{ __('opening_stock_quantity_correction.reject') }}</button></div>
        </form>
    @endcan
    @endif
</div></div>
@empty<p class="text-muted">{{ __('opening_stock_quantity_correction.empty') }}</p>@endforelse
@endsection
