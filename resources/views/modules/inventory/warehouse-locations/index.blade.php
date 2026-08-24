@extends('layouts.app')

@section('title', __('Warehouse Locations'))

@section('content')
<div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('Create Warehouse Location') }}</h5></div><div class="card-body"><form method="POST" action="{{ route('admin.inventory.warehouse-locations.store') }}" class="row g-3 align-items-end">@csrf
    <div class="col-md-3"><label class="form-label">{{ __('Store') }}</label><select class="form-select" name="branch_store_id" required>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select></div>
    <div class="col-md-2"><label class="form-label">{{ __('Zone') }}</label><input class="form-control" name="zone_code"></div>
    <div class="col-md-2"><label class="form-label">{{ __('Code') }}</label><input class="form-control" name="code" required></div>
    <div class="col-md-3"><label class="form-label">{{ __('Name') }}</label><input class="form-control" name="name" required></div>
    <div class="col-md-2"><button class="btn btn-primary w-100">{{ __('Create') }}</button></div>
</form></div></div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>{{ __('Store') }}</th><th>{{ __('Zone') }}</th><th>{{ __('Code') }}</th><th>{{ __('Name') }}</th><th>{{ __('Active') }}</th></tr></thead><tbody>@foreach($locations as $location)<tr><td>{{ $location->branchStore?->name }}</td><td>{{ $location->zone_code }}</td><td>{{ $location->code }}</td><td>{{ $location->name }}</td><td><form method="POST" action="{{ route('admin.inventory.warehouse-locations.status', $location) }}">@csrf @method('PATCH')<input type="hidden" name="is_active" value="{{ $location->is_active ? 0 : 1 }}"><button class="btn btn-sm {{ $location->is_active ? 'btn-success' : 'btn-outline-secondary' }}">{{ $location->is_active ? __('Active') : __('Inactive') }}</button></form></td></tr>@endforeach</tbody></table></div></div>
@endsection
