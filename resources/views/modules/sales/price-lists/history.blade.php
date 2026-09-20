@extends('layouts.app')
@section('title', __('price_lists.history').' '.$record->doc_num)
@section('content')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
@endphp
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">{{ __('price_lists.history') }} — {{ $record->doc_num }}</h5>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.price-lists.show', $record) }}">{{ __('common.actions.back') }}</a>
    </div>
    <div class="card-body">
        @if($activity->isEmpty())
            <p class="text-muted">{{ __('price_lists.messages.no_history') }}</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="bg-100">
                    <tr>
                        <th>{{ __('price_lists.history_fields.date') }}</th>
                        <th>{{ __('price_lists.history_fields.actor') }}</th>
                        <th>{{ __('price_lists.history_fields.action') }}</th>
                        <th>{{ __('price_lists.history_fields.details') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($activity as $log)
                        <tr>
                            <td>{{ $dates->formatDateTime($log->created_at, '') }}</td>
                            <td>
                                @if($log->causer)
                                    <span dir="ltr">{{ $log->causer->name }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            @php
                                $changeType = data_get($log->properties, 'change_type');
                                $headerChanges = data_get($log->properties, 'header_changes', []);
                                $lineChanges = data_get($log->properties, 'line_changes', []);
                            @endphp
                            <td>{{ $changeType ? __('price_lists.history_actions.'.$changeType) : ($log->description ?: __('common.empty_value')) }}</td>
                            <td>
                                @if(data_get($log->properties, 'percentage'))
                                    <div class="mb-2"><strong>{{ __('price_lists.history_fields.percentage') }}:</strong> {{ $numbers->format(data_get($log->properties, 'percentage')) }}%</div>
                                @endif
                                @if(data_get($log->properties, 'lifecycle_invalidated'))
                                    <div class="alert alert-warning py-1 px-2 mb-2">{{ __('price_lists.messages.lifecycle_invalidated') }}</div>
                                @endif
                                @foreach($headerChanges as $field => $change)
                                    @php
                                        $fieldLabel = $field === 'price_list_date' ? __('price_lists.fields.date') : __('price_lists.fields.'.$field);
                                        $oldValue = data_get($change, 'old');
                                        $newValue = data_get($change, 'new');
                                        if ($field === 'is_print_only') {
                                            $oldValue = $oldValue ? __('common.actions.yes') : __('common.actions.no');
                                            $newValue = $newValue ? __('common.actions.yes') : __('common.actions.no');
                                        } elseif (in_array($field, ['price_list_date', 'valid_from', 'valid_until'], true)) {
                                            $oldValue = $oldValue ? $dates->formatDate($oldValue, __('common.empty_value')) : __('common.empty_value');
                                            $newValue = $newValue ? $dates->formatDate($newValue, __('common.empty_value')) : __('common.empty_value');
                                        } else {
                                            $oldValue = filled($oldValue) ? $oldValue : __('common.empty_value');
                                            $newValue = filled($newValue) ? $newValue : __('common.empty_value');
                                        }
                                    @endphp
                                    <div class="mb-2">
                                        <strong>{{ $fieldLabel }}:</strong>
                                        {{ $oldValue }}
                                        <span class="mx-1">&rarr;</span>
                                        {{ $newValue }}
                                    </div>
                                @endforeach
                                @foreach($lineChanges as $change)
                                    @php($old = data_get($change, 'old'))
                                    @php($new = data_get($change, 'new'))
                                    <div class="border rounded p-2 mb-2">
                                        <strong>{{ data_get($change, 'product') ?: __('common.empty_value') }}</strong>
                                        <div class="small mt-1">
                                            {{ __('price_lists.fields.price') }}:
                                            {{ $old ? $numbers->format(data_get($old, 'unit_price')) : __('common.empty_value') }}
                                            <span class="mx-1">&rarr;</span>
                                            {{ $new ? $numbers->format(data_get($new, 'unit_price')) : __('common.empty_value') }}
                                        </div>
                                        <div class="small">
                                            {{ __('price_lists.fields.discount_type') }}:
                                            {{ data_get($old, 'allowed_discount_type') ? __('price_lists.'.data_get($old, 'allowed_discount_type')) : __('price_lists.no_discount') }}
                                            / {{ $old ? $numbers->format(data_get($old, 'allowed_discount_value', 0)) : __('common.empty_value') }}
                                            <span class="mx-1">&rarr;</span>
                                            {{ data_get($new, 'allowed_discount_type') ? __('price_lists.'.data_get($new, 'allowed_discount_type')) : __('price_lists.no_discount') }}
                                            / {{ $new ? $numbers->format(data_get($new, 'allowed_discount_value', 0)) : __('common.empty_value') }}
                                        </div>
                                    </div>
                                @endforeach
                                @if(empty($headerChanges) && empty($lineChanges) && !data_get($log->properties, 'percentage') && !in_array($changeType, ['review', 'approve'], true))—@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
