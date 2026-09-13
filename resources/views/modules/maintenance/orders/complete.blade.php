@extends('layouts.app')

@section('title', __('maintenance.orders.complete'))

@section('content')
    <div class="production-mobile-workflow">
    @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('admin.maintenance.orders.complete', $order) }}" class="card">@csrf
        <div class="card-header"><h5 class="mb-0">{{ $order->doc_num }} — {{ $order->asset?->asset_name }}</h5></div>
        <div class="card-body"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">{{ __('maintenance.fields.diagnosis') }}</label><x-forms.textarea class="form-control" name="diagnosis" rows="4" required>{{ old('diagnosis') }}</x-forms.textarea></div>
            <div class="col-md-6"><label class="form-label">{{ __('maintenance.fields.root_cause') }}</label><x-forms.textarea class="form-control" name="root_cause" rows="4">{{ old('root_cause') }}</x-forms.textarea></div>
            <div class="col-12"><label class="form-label">{{ __('maintenance.fields.work_performed') }}</label><x-forms.textarea class="form-control" name="work_performed" rows="4" required>{{ old('work_performed') }}</x-forms.textarea></div>
            <div class="col-md-8"><label class="form-label">{{ __('maintenance.fields.completion_notes') }}</label><x-forms.textarea class="form-control" name="completion_notes" rows="2">{{ old('completion_notes') }}</x-forms.textarea></div>
            <div class="col-md-4"><label class="form-label">{{ __('maintenance.fields.next_due_date') }}</label><x-forms.date-input name="next_due_date" :value="old('next_due_date')" /></div>
        </div></div>
        <div class="card-footer text-end"><button class="btn btn-success">{{ __('maintenance.actions.complete') }}</button></div>
    </form>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
