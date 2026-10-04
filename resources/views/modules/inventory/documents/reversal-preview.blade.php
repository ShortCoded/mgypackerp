@extends('layouts.app')

@section('title', __('inventory.movements.reversal.title').' — '.$record->doc_num)

@section('content')
    @php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
    <div class="card mb-3">
        <div class="card-header d-flex align-items-center justify-content-between gap-2">
            <div>
                <h5 class="mb-1">{{ __('inventory.movements.reversal.title') }} — {{ $record->doc_num }}</h5>
                <div class="small text-muted">{{ __('inventory.movements.reversal.help') }}</div>
            </div>
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.inventory.documents.show', $record) }}">{{ __('common.actions.back') }}</a>
        </div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            <form class="row g-2 mb-3" method="GET" action="{{ route('admin.inventory.documents.reversal-preview', $record) }}" novalidate>
                <div class="col-md-4"><x-forms.label for="inventory-reversal-date" :label="__('inventory.movements.reversal.posting_date')" /><x-forms.date-input id="inventory-reversal-date" name="posting_date" :value="$plan['posting_date']" /></div>
                <div class="col-md-4 d-flex align-items-end"><button class="btn btn-falcon-default" type="submit">{{ __('inventory.movements.reversal.refresh_preview') }}</button></div>
            </form>
            @if(!$plan['can_reverse'])
                <div class="alert alert-warning"><strong>{{ __('inventory.movements.reversal.blocked') }}</strong><ul class="mb-0 mt-2">@foreach($plan['blockers'] as $blocker)<li>{{ $blocker }}</li>@endforeach</ul></div>
            @endif
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('inventory.movements.fields.product') }}</th>
                        <th>{{ __('inventory.movements.fields.store') }}</th>
                        <th>{{ __('inventory.movements.fields.source_status') }}</th>
                        <th>{{ __('inventory.movements.reversal.before_quantity') }}</th>
                        <th>{{ __('inventory.movements.reversal.reversal_quantity') }}</th>
                        <th>{{ __('inventory.movements.reversal.after_quantity') }}</th>
                        <th>{{ __('inventory.movements.reversal.original_layer_available') }}</th>
                        <th>{{ __('inventory.movements.reversal.value_delta') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($plan['lines'] as $line)
                            <tr>
                                <td>{{ $line['product'] }}</td>
                                <td>{{ $line['store'] ?: '—' }}</td>
                                <td>{{ __('inventory.movements.stock_statuses.'.$line['stock_status']) }}</td>
                                <td>{{ $numbers->format($line['before_quantity']) }}</td>
                                <td>{{ $numbers->format($line['quantity_in'] !== '0.00000000' ? '-'.$line['quantity_in'] : $line['quantity_out']) }}</td>
                                <td>{{ $numbers->format($line['after_quantity']) }}</td>
                                <td>{{ $line['original_layer_available'] === null ? '—' : $numbers->format($line['original_layer_available']) }}</td>
                                <td>{{ $line['value_delta'] === null ? '—' : $numbers->format($line['value_delta']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($plan['can_reverse'])
                <form class="mt-3" method="POST" action="{{ route('admin.inventory.documents.reverse', $record) }}" novalidate>
                    @csrf
                    <x-forms.input type="hidden" name="posting_date" :value="$plan['posting_date']" />
                    <x-forms.input type="hidden" name="preview_token" :value="$plan['preview_token']" />
                    <x-forms.label for="inventory-reversal-reason" :label="__('inventory.movements.reversal.reason')" required />
                    <x-forms.textarea id="inventory-reversal-reason" name="reason" rows="2" maxlength="2000">{{ old('reason') }}</x-forms.textarea>
                    <div class="small text-muted mt-1">{{ __('inventory.movements.reversal.recheck_notice') }}</div>
                    <button class="btn btn-danger mt-3" type="submit">{{ __('inventory.movements.reversal.confirm') }}</button>
                </form>
            @endif
        </div>
    </div>
@endsection
