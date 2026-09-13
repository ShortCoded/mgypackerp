@extends('layouts.app')

@section('title', __('production_execution.product_stages.configure'))

@section('content')
    <div class="production-mobile-workflow">
    @php($selected = $routeStages->keyBy('production_stage_id'))
    <form method="POST" action="{{ route('admin.production.product-stages.update', $product) }}">
        @csrf @method('PUT')
        <x-forms.input type="hidden" name="product_id" value="{{ $product->id }}" />
        <div class="card">
            <div class="card-header"><h5 class="mb-0">{{ $product->doc_num }} — {{ $product->name }}</h5><div class="text-600">{{ __('production_execution.product_stages.optional_route_help') }}</div></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>{{ __('production_execution.fields.use') }}</th><th>{{ __('production_execution.fields.order') }}</th><th>{{ __('production_execution.fields.stage') }}</th><th>{{ __('production_execution.fields.output_type') }}</th><th>{{ __('production_execution.fields.standard_duration') }}</th></tr></thead><tbody>
                @foreach($stages as $stage)
                    @php($route = $selected->get($stage->id))
                    <tr><td><x-forms.input class="form-check-input" type="checkbox" name="selected_stage_ids[]" value="{{ $stage->id }}" :checked='$route' /></td><td><x-forms.input class="form-control form-control-sm" type="number" min="1" name="stage_sequences[{{ $stage->id }}]" value="{{ old('stage_sequences.'.$stage->id, $route?->sequence ?? $stage->display_order) }}" /></td><td>{{ $stage->code }} — {{ $stage->name }}</td><td>{{ $stage->output_type ?: '—' }}</td><td>{{ $stage->standard_duration_value ? $stage->standard_duration_value.' '.__('production_execution.duration_units.'.$stage->standard_duration_unit) : '—' }}</td></tr>
                @endforeach
            </tbody></table></div>
            <div class="card-footer d-flex justify-content-end gap-2"><a class="btn btn-falcon-default" href="{{ route('admin.production.product-stages.index') }}">{{ __('common.actions.cancel') }}</a><button class="btn btn-primary">{{ __('common.actions.save') }}</button></div>
        </div>
    </form>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
