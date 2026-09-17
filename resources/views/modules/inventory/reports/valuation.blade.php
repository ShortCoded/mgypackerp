@extends('layouts.app')

@section('title', __('inventory_accounting.valuation_report.title'))

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-1">{{ __('inventory_accounting.valuation_report.title') }}</h4>
            <p class="text-muted mb-0">{{ __('inventory_accounting.valuation_report.description') }}</p>
        </div>
        <button class="btn btn-outline-secondary d-print-none" type="button" onclick="window.print()">
            <span class="fas fa-print me-1"></span>{{ __('Print') }}
        </button>
    </div>

    <div class="alert alert-info" role="status">
        <strong>{{ __('inventory_accounting.valuation_report.book_policy') }}:</strong>
        {{ __('inventory_accounting.valuation_report.book_policy_note') }}
    </div>

    <form class="card card-body mb-3 d-print-none" method="GET" action="{{ route('admin.inventory.reports.valuation') }}">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4">
                <label class="form-label" for="valuation-product">{{ __('inventory_accounting.valuation_report.product') }}</label>
                <select class="form-select" id="valuation-product" name="product_id" required>
                    <option value="">{{ __('inventory_accounting.valuation_report.select') }}</option>
                    @foreach($products as $product)
                        <option value="{{ $product->getKey() }}" @selected($selectedProduct?->is($product))>{{ $product->doc_num }} — {{ $product->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label" for="valuation-store">{{ __('inventory_accounting.valuation_report.store') }}</label>
                <select class="form-select" id="valuation-store" name="branch_store_id" required>
                    <option value="">{{ __('inventory_accounting.valuation_report.select') }}</option>
                    @foreach($stores as $store)
                        <option value="{{ $store->getKey() }}" @selected($selectedStore?->is($store))>{{ $store->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-3">
                <label class="form-label" for="valuation-as-of">{{ __('inventory_accounting.valuation_report.as_of') }}</label>
                <x-forms.date-input id="valuation-as-of" name="as_of" :value="$asOf" :min="$period->from_date->toDateString()" :max="$period->to_date->toDateString()" required />
            </div>
            <div class="col-lg-2">
                <button class="btn btn-primary w-100" type="submit">{{ __('inventory_accounting.valuation_report.run') }}</button>
            </div>
        </div>
    </form>

    @if($comparisonError)
        <div class="alert alert-danger" role="alert">{{ $comparisonError }}</div>
    @elseif($comparison)
        <div class="row g-3 mb-3">
            @foreach([
                'available_quantity' => 'available_quantity',
                'available_cost' => 'available_cost',
                'issued_quantity' => 'issued_quantity',
                'ending_quantity' => 'ending_quantity',
            ] as $valueKey => $labelKey)
                <div class="col-md-6 col-xl-3">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-muted small">{{ __('inventory_accounting.valuation_report.'.$labelKey) }}</div>
                        <div class="fs-4 fw-semibold">{{ $numbers->format($comparison[$valueKey]) }}</div>
                    </div></div>
                </div>
            @endforeach
        </div>

        <div class="card mb-3">
            <div class="card-header"><h5 class="mb-0">{{ __('inventory_accounting.valuation_report.title') }}</h5></div>
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead><tr>
                        <th>{{ __('inventory_accounting.valuation_report.method') }}</th>
                        <th class="text-end">{{ __('inventory_accounting.valuation_report.issue_cost') }}</th>
                        <th class="text-end">{{ __('inventory_accounting.valuation_report.ending_value') }}</th>
                        <th class="text-end">{{ __('inventory_accounting.valuation_report.ending_unit_cost') }}</th>
                        <th>{{ __('inventory_accounting.valuation_report.classification') }}</th>
                    </tr></thead>
                    <tbody>
                        @foreach($comparison['methods'] as $method => $result)
                            <tr>
                                <td>{{ __('inventory_accounting.valuation_methods.'.$method) }}</td>
                                <td class="text-end">{{ $result['issue_cost'] === null ? '—' : $numbers->format($result['issue_cost']) }}</td>
                                <td class="text-end">{{ $numbers->format($result['ending_value']) }}</td>
                                <td class="text-end">{{ $numbers->format($result['ending_unit_cost']) }}</td>
                                <td>
                                    @if($result['book_method'])
                                        <span class="badge bg-success">{{ __('inventory_accounting.valuation_report.book_method') }}</span>
                                    @elseif($result['reference_only'])
                                        <span class="badge bg-warning text-dark">{{ __('inventory_accounting.valuation_report.reference_only') }}</span>
                                    @else
                                        <span class="badge bg-secondary">{{ __('inventory_accounting.valuation_report.simulation') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">{{ __('inventory_accounting.valuation_report.sources') }}</h5>
                <span class="text-muted">{{ __('inventory_accounting.valuation_report.source_count', ['count' => $comparison['source_count']]) }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr>
                        <th>{{ __('inventory_accounting.valuation_report.date') }}</th>
                        <th>{{ __('inventory_accounting.valuation_report.document') }}</th>
                        <th>{{ __('inventory_accounting.valuation_report.type') }}</th>
                        <th class="text-end">{{ __('inventory_accounting.valuation_report.quantity_in') }}</th>
                        <th class="text-end">{{ __('inventory_accounting.valuation_report.quantity_out') }}</th>
                        <th class="text-end">{{ __('inventory_accounting.valuation_report.unit_cost') }}</th>
                        <th class="text-end">{{ __('inventory_accounting.valuation_report.total_cost') }}</th>
                    </tr></thead>
                    <tbody>
                        @forelse($comparison['sources'] as $source)
                            <tr>
                                <td>{{ $source['date'] }}</td>
                                <td>{{ $source['document'] }}</td>
                                <td>{{ __('inventory.movements.types.'.$source['type']) }}</td>
                                <td class="text-end">{{ $numbers->format($source['quantity_in']) }}</td>
                                <td class="text-end">{{ $numbers->format($source['quantity_out']) }}</td>
                                <td class="text-end">{{ $numbers->format($source['unit_cost']) }}</td>
                                <td class="text-end">{{ $numbers->format($source['total_cost']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted">{{ __('inventory_accounting.valuation_report.no_sources') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="card"><div class="card-body text-center text-muted py-5">{{ __('inventory_accounting.valuation_report.empty') }}</div></div>
    @endif
</div>
@endsection
