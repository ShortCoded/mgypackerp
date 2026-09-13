@extends('layouts.app')

@section('title', __('Physical Stock Counts'))

@section('content')
<div class="production-mobile-workflow">
<div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('Create Count Snapshot') }}</h5></div><div class="card-body"><form method="POST" action="{{ route('admin.inventory.stock-counts.store') }}" class="row g-3 align-items-end">@csrf
    <div class="col-md-4"><label class="form-label">{{ __('Store') }}</label><x-forms.select class="form-select" name="branch_store_id" required>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</x-forms.select></div>
    <div class="col-md-3"><label class="form-label">{{ __('Stock status') }}</label><x-forms.select class="form-select" name="stock_status"><option value="">{{ __('All') }}</option>@foreach($stockStatuses as $status)<option value="{{ $status }}">{{ __('inventory.movements.stock_statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
    <div class="col-md-3"><label class="form-label">{{ __('Count date') }}</label><x-forms.date-input name="count_date" :value="now()->toDateString()" required /></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">{{ __('Create') }}</button></div>
</form></div></div>
<div class="card"><div class="card-header"><h5 class="mb-0">{{ __('Count Sessions') }}</h5></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Store') }}</th><th>{{ __('Status') }}</th><th>{{ __('Approved') }}</th></tr></thead><tbody>@forelse($records as $record)<tr><td><a href="{{ route('admin.inventory.stock-counts.show', $record) }}">{{ $record->doc_num }}</a></td><td>{{ $record->count_date?->toDateString() }}</td><td>{{ $record->branchStore?->name }}</td><td>{{ __('inventory.movements.statuses.'.$record->status) }}</td><td>{{ $record->approved_at }}</td></tr>@empty<tr><td colspan="5" class="text-center py-5 text-500">{{ __('No count sessions found.') }}</td></tr>@endforelse</tbody></table></div>@if($records->hasPages())<div class="card-footer">{{ $records->links() }}</div>@endif</div>
</div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
