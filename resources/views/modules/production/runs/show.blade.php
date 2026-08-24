@extends('layouts.app')

@section('title', $record->run_number)

@section('content')
    @if ($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between">
            <div><h5 class="mb-1">{{ $record->run_number }}</h5><span class="badge bg-secondary">{{ str($record->status)->title() }}</span></div>
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.runs.print', $record) }}">{{ __('Print traveler') }}</a>
        </div>
        <div class="card-body"><div class="row g-2">
            <div class="col-md-3"><strong>{{ __('Order') }}:</strong> {{ $record->order?->doc_num }}</div>
            <div class="col-md-3"><strong>{{ __('Product') }}:</strong> {{ $record->product?->name }}</div>
            <div class="col-md-3"><strong>{{ __('Planned') }}:</strong> {{ $record->planned_base_quantity }}</div>
            <div class="col-md-3"><strong>{{ __('Good / Received') }}:</strong> {{ $record->good_base_quantity }} / {{ $record->received_base_quantity }}</div>
        </div></div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('Material Reconciliation') }}</h5></div>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>{{ __('Material') }}</th><th>{{ __('Planned') }}</th><th>{{ __('Reserved') }}</th><th>{{ __('Issued') }}</th><th>{{ __('Additional') }}</th><th>{{ __('Returned') }}</th><th>{{ __('Consumed') }}</th><th>{{ __('Waste') }}</th></tr></thead>
            <tbody>@foreach ($record->requirements as $line)<tr>
                <td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td>
                <td>{{ $line->planned_quantity }}</td><td>{{ $line->reserved_quantity }}</td><td>{{ $line->issued_quantity }}</td>
                <td>{{ $line->additional_issued_quantity }}</td><td>{{ $line->returned_quantity }}</td><td>{{ $line->consumed_quantity }}</td><td>{{ $line->waste_quantity }}</td>
            </tr>@endforeach</tbody>
        </table></div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('Controlled Actions') }}</h5></div>
        <div class="card-body d-flex flex-wrap gap-2">
            <form method="POST" action="{{ route('admin.production.runs.setup.start', $record) }}">@csrf<button class="btn btn-falcon-primary btn-sm">{{ __('Start setup') }}</button></form>
            <form method="POST" action="{{ route('admin.production.runs.setup.complete', $record) }}">@csrf<button class="btn btn-falcon-primary btn-sm">{{ __('Complete setup') }}</button></form>
            <form method="POST" action="{{ route('admin.production.runs.start', $record) }}">@csrf<button class="btn btn-primary btn-sm">{{ __('Start run') }}</button></form>
            <form method="POST" action="{{ route('admin.production.runs.resume', $record) }}">@csrf<button class="btn btn-warning btn-sm">{{ __('Resume after QC pass') }}</button></form>
            <form method="POST" action="{{ route('admin.production.runs.complete', $record) }}">@csrf<button class="btn btn-success btn-sm">{{ __('Complete run') }}</button></form>
            <form class="d-flex gap-2" method="POST" action="{{ route('admin.production.runs.cancel', $record) }}">@csrf<input class="form-control form-control-sm" name="reason" placeholder="{{ __('Cancellation reason') }}" required><button class="btn btn-outline-danger btn-sm">{{ __('Cancel') }}</button></form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4"><form class="card h-100" method="POST" action="{{ route('admin.production.runs.reserve', $record) }}">
            @csrf
            <div class="card-header"><h6 class="mb-0">{{ __('Reserve / Issue Planned Materials') }}</h6></div>
            <div class="card-body"><select class="form-select" name="branch_store_id" required>@foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select></div>
            <div class="card-footer d-flex gap-2"><button class="btn btn-falcon-primary btn-sm">{{ __('Reserve') }}</button><button class="btn btn-primary btn-sm" formaction="{{ route('admin.production.runs.issue', $record) }}">{{ __('Issue planned') }}</button></div>
        </form></div>

        @foreach ([['route' => 'admin.production.runs.issue', 'title' => __('Additional Material Issue'), 'button' => __('Issue additional'), 'additional' => true], ['route' => 'admin.production.runs.return', 'title' => __('Unused Material Return'), 'button' => __('Return unused'), 'additional' => false]] as $materialAction)
            <div class="col-lg-4"><form class="card h-100" method="POST" action="{{ route($materialAction['route'], $record) }}">
                @csrf
                @if ($materialAction['additional'])<input type="hidden" name="additional" value="1">@endif
                <div class="card-header"><h6 class="mb-0">{{ $materialAction['title'] }}</h6></div>
                <div class="card-body">
                    <select class="form-select mb-2" name="branch_store_id" required>@foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select>
                    @foreach ($record->requirements as $index => $line)
                        <div class="input-group input-group-sm mb-2"><span class="input-group-text flex-grow-1">{{ $line->product?->name }}</span><input type="hidden" name="lines[{{ $index }}][requirement_id]" value="{{ $line->id }}"><input class="form-control" type="number" step="0.00000001" min="0.00000001" name="lines[{{ $index }}][quantity]" placeholder="{{ __('Qty') }}"></div>
                    @endforeach
                </div>
                <div class="card-footer text-end"><button class="btn btn-primary btn-sm">{{ $materialAction['button'] }}</button></div>
            </form></div>
        @endforeach

        <div class="col-lg-4"><form class="card h-100" method="POST" action="{{ route('admin.production.runs.progress', $record) }}">
            @csrf
            <div class="card-header"><h6 class="mb-0">{{ __('Production Progress') }}</h6></div>
            <div class="card-body row g-2">
                <div class="col-6"><input class="form-control" type="number" step="0.00000001" min="0" name="good_base_quantity" placeholder="{{ __('Good') }}"></div>
                <div class="col-6"><input class="form-control" type="number" step="0.00000001" min="0" name="rejected_base_quantity" placeholder="{{ __('Rejected') }}"></div>
                <div class="col-6"><input class="form-control" type="number" step="0.00000001" min="0" name="rework_base_quantity" placeholder="{{ __('Rework') }}"></div>
                <div class="col-6"><input class="form-control" type="number" step="0.00000001" min="0" name="scrap_base_quantity" placeholder="{{ __('Scrap') }}"></div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary btn-sm">{{ __('Record progress') }}</button></div>
        </form></div>

        <div class="col-lg-4"><form class="card h-100" method="POST" enctype="multipart/form-data" action="{{ route('admin.production.runs.inspect', $record) }}">
            @csrf
            <div class="card-header"><h6 class="mb-0">{{ __('In-Process Quality Sample') }}</h6></div>
            <div class="card-body"><select class="form-select mb-2" name="result"><option value="passed">{{ __('Passed') }}</option><option value="conditional">{{ __('Conditional') }}</option><option value="failed">{{ __('Failed / Hold') }}</option></select><input class="form-control mb-2" name="notes" placeholder="{{ __('Observation') }}"><input class="form-control" type="file" name="evidence_file" accept="image/*" capture="environment"></div>
            <div class="card-footer text-end"><button class="btn btn-primary btn-sm">{{ __('Record QC sample') }}</button></div>
        </form></div>

        <div class="col-lg-6"><form class="card h-100" method="POST" action="{{ route('admin.production.runs.account', $record) }}">
            @csrf
            <div class="card-header"><h6 class="mb-0">{{ __('Material Accountability') }}</h6></div>
            <div class="card-body">
                <select class="form-select mb-2" name="branch_store_id" required>@foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select>
                @foreach ($record->requirements as $index => $line)
                    @php($unaccounted = bcsub(bcsub(bcadd($line->issued_quantity, $line->additional_issued_quantity, 8), $line->returned_quantity, 8), bcadd($line->consumed_quantity, $line->waste_quantity, 8), 8))
                    <div class="row g-2 mb-2"><input type="hidden" name="lines[{{ $index }}][requirement_id]" value="{{ $line->id }}"><div class="col-4">{{ $line->product?->name }}</div><div class="col-4"><input class="form-control form-control-sm" type="number" step="0.00000001" min="0" name="lines[{{ $index }}][consumed_quantity]" value="{{ $unaccounted }}" aria-label="{{ __('Consumed') }}"></div><div class="col-4"><input class="form-control form-control-sm" type="number" step="0.00000001" min="0" name="lines[{{ $index }}][waste_quantity]" value="0" aria-label="{{ __('Waste') }}"></div></div>
                @endforeach
            </div>
            <div class="card-footer text-end"><button class="btn btn-primary btn-sm">{{ __('Post consumption / waste') }}</button></div>
        </form></div>

        <div class="col-lg-6"><form class="card h-100" method="POST" action="{{ route('admin.production.runs.receive', $record) }}">
            @csrf
            <div class="card-header"><h6 class="mb-0">{{ __('Finished Goods Receipt') }}</h6></div>
            <div class="card-body row g-2"><div class="col-7"><select class="form-select" name="branch_store_id" required>@foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select></div><div class="col-5"><input class="form-control" type="number" step="0.00000001" min="0.00000001" name="base_quantity" placeholder="{{ __('Accepted base qty') }}" required></div></div>
            <div class="card-footer text-end"><button class="btn btn-success btn-sm">{{ __('Receive finished goods') }}</button></div>
        </form></div>
    </div>
@endsection
