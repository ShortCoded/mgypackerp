@extends('layouts.app')

@section('title', __('Inventory Movements'))

@section('content')
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">{{ __('Inventory Movements') }}</h5>
        <a class="btn btn-primary btn-sm" href="{{ route('admin.inventory.documents.create') }}">{{ __('New movement') }}</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>{{ __('Document') }}</th><th>{{ __('Type') }}</th><th>{{ __('Date') }}</th><th>{{ __('Source') }}</th><th>{{ __('Destination') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody>
            @forelse($records as $record)
                <tr>
                    <td><a href="{{ route('admin.inventory.documents.show', $record) }}">{{ $record->doc_num }}</a></td>
                    <td>{{ str($record->document_type)->replace('_', ' ')->title() }}</td>
                    <td>{{ $record->document_date?->toDateString() }}</td>
                    <td>{{ $record->branchStore?->name }}</td>
                    <td>{{ $record->destinationBranchStore?->name }}</td>
                    <td><span class="badge bg-secondary">{{ str($record->status)->title() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center py-5 text-500">{{ __('No inventory movements found.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($records->hasPages())<div class="card-footer">{{ $records->links() }}</div>@endif
</div>
@endsection
