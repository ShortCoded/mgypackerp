@extends('layouts.app')

@section('title', __('Create Purchase Requisition'))

@section('content')
    <form method="POST" action="{{ route('admin.purchases.purchase-requisitions.store') }}" data-procurement-form data-lines-container="#requisition-lines" data-line-template="#requisition-line-template">
        @csrf
        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ __('Create Purchase Requisition') }}</h5></div>
            <div class="card-body">
                @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
                <div class="row g-3">
                    <div class="col-md-3"><label class="form-label">{{ __('Request date') }}</label><input class="form-control" type="date" name="request_date" value="{{ old('request_date', now()->toDateString()) }}" required></div>
                    <div class="col-md-3"><label class="form-label">{{ __('Required by') }}</label><input class="form-control" type="date" name="required_by_date" value="{{ old('required_by_date') }}"></div>
                    <div class="col-md-3"><label class="form-label">{{ __('Destination store') }}</label><select class="form-select js-select2" name="branch_store_uuid"><option value="">{{ __('Select') }}</option>@foreach($stores as $store)<option value="{{ $store->public_uuid }}">{{ $store->doc_num }} / {{ $store->name }}</option>@endforeach</select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('Priority') }}</label><select class="form-select" name="priority"><option value="normal">{{ __('Normal') }}</option><option value="low">{{ __('Low') }}</option><option value="high">{{ __('High') }}</option><option value="urgent">{{ __('Urgent') }}</option></select></div>
                    <div class="col-md-4"><label class="form-label">{{ __('Department') }}</label><input class="form-control" name="department" value="{{ old('department') }}"></div>
                    <div class="col-md-8"><label class="form-label">{{ __('Notes') }}</label><input class="form-control" name="notes" value="{{ old('notes') }}"></div>
                </div>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center"><h6 class="mb-0">{{ __('Requirement lines') }}</h6><button class="btn btn-falcon-primary btn-sm" type="button" data-add-procurement-line>{{ __('Add line') }}</button></div>
            <div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table"><thead class="bg-100"><tr><th>#</th><th>{{ __('Item / service') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('Required date') }}</th><th>{{ __('Demand source') }}</th><th>{{ __('Source document') }}</th><th>{{ __('Source line') }}</th><th>{{ __('Specification') }}</th><th></th></tr></thead><tbody id="requisition-lines">
                @foreach(old('lines', [[]]) as $index => $line)
                    @include('modules.purchases.procurement.partials.requisition-line', ['index' => $index, 'line' => $line, 'products' => $products])
                @endforeach
            </tbody></table></div></div>
        </div>
        <div class="d-flex justify-content-end"><button class="btn btn-primary" type="submit">{{ __('Save draft') }}</button></div>
    </form>
    <template id="requisition-line-template">@include('modules.purchases.procurement.partials.requisition-line', ['index' => '__INDEX__', 'line' => [], 'products' => $products])</template>
@endsection

@push('scripts')<script src="{{ asset('assets/js/modules/Purchases/procurement-cycle.js') }}"></script>@endpush
