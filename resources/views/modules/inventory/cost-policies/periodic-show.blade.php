@extends('layouts.app')
@section('title', __('inventory_periodic_cost.title').' — '.$close->doc_num)
@section('content')
@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $plan = $close->impact_snapshot;
    $locale = app()->getLocale() === 'ar' ? 'ar' : 'en';
@endphp
<div class="container-fluid py-3">
    <x-admin.report.actions-toolbar class="mb-3" :show-filters="false" :show-refresh="false" :export-options="[
        ['permission' => 'inventory.cost_policies.periodic.export', 'url' => route('admin.inventory.periodic-cost-closes.export', [$close, 'format' => 'xlsx']), 'label' => __('reports.export_excel'), 'icon' => 'file-excel'],
        ['permission' => 'inventory.cost_policies.periodic.export', 'url' => route('admin.inventory.periodic-cost-closes.export', [$close, 'format' => 'csv']), 'label' => __('reports.export_csv'), 'icon' => 'file-csv'],
        ['permission' => 'inventory.cost_policies.periodic.print', 'url' => route('admin.inventory.periodic-cost-closes.print', $close), 'label' => __('reports.export_pdf'), 'icon' => 'file-pdf', 'newTab' => true],
    ]" />
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="card mb-3"><div class="card-body"><h4>{{ __('inventory_periodic_cost.title') }} — {{ $close->doc_num }}</h4><div class="d-flex gap-3 flex-wrap"><span>{{ __('inventory_periodic_cost.statuses.'.$close->status) }}</span><span>{{ $close->scopeStore?->name ?? $close->scopeBranch?->name ?? __('inventory_cost_policy.company_scope') }}</span><span>{{ $dates->formatDate($close->from_date, '') }} — {{ $dates->formatDate($close->to_date, '') }}</span><span>{{ __('inventory_periodic_cost.posting_date') }}: {{ $dates->formatDate($close->posting_date, '') }}</span></div><p class="mt-2 mb-0">{{ $close->reason }}</p>
        @if($close->valueAdjustment?->journalEntry)@can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $close->valueAdjustment->journalEntry) }}">{{ $close->valueAdjustment->journalEntry->doc_num }}</a>@endcan @endif
        @if($close->approval_reference)<div>{{ __('inventory_periodic_cost.reference') }}: {{ $close->approval_reference }}</div>@endif
        @if($close->rejection_reason)<div>{{ __('inventory_periodic_cost.rejection_reason') }}: {{ $close->rejection_reason }}</div>@endif
    </div></div>
    <x-admin.report.table-card :title="__('inventory_periodic_cost.inputs')" table-id="periodic-cost-inputs" class="mb-3">
        <thead><tr><th>{{ __('inventory_cost_policy.store') }}</th><th>{{ __('Product') }}</th><th>{{ __('Status') }}</th>@foreach(['opening_quantity','opening_value','receipt_quantity','receipt_value','average','closing_quantity'] as $field)<th>{{ __('inventory_periodic_cost.'.$field) }}</th>@endforeach</tr></thead><tbody>
        @foreach($plan['period_inputs'] as $input)<tr><td>{{ $close->scope_snapshot['store_labels'][$input['store_id']] }}</td><td>{{ $input['product_code'] }} — {{ $input['product_label'] }}</td><td>{{ __('inventory.movements.stock_statuses.'.$input['stock_status']) }}</td>@foreach(['opening_quantity','opening_value','receipt_quantity','receipt_value','average','closing_quantity'] as $field)<td class="text-nowrap">{{ $numbers->format($input[$field], 8) }}</td>@endforeach</tr>@endforeach
        </tbody>
    </x-admin.report.table-card>
    <x-admin.report.table-card :title="__('inventory_periodic_cost.outflows')" table-id="periodic-cost-outflows" class="mb-3">
        <thead><tr><th>{{ __('Document') }}</th><th>{{ __('inventory_cost_policy.store') }}</th><th>{{ __('Product') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('inventory_periodic_cost.provisional') }}</th><th>{{ __('inventory_periodic_cost.final') }}</th><th>{{ __('inventory_periodic_cost.difference') }}</th></tr></thead><tbody>
        @foreach($plan['period_inputs'] as $input)@foreach($input['outflows'] as $outflow)<tr><td>{{ $outflow['document'] }}</td><td>{{ $close->scope_snapshot['store_labels'][$input['store_id']] }}</td><td>{{ $input['product_code'] }} — {{ $input['product_label'] }}</td><td>{{ $numbers->format($outflow['quantity'], 8) }}</td><td>{{ $outflow['provisional_cost'] === null ? '—' : $numbers->format($outflow['provisional_cost'], 8) }}</td><td>{{ $numbers->format($outflow['final_cost'], 8) }}</td><td>{{ $numbers->format($outflow['difference'], 8) }}</td></tr>@endforeach @endforeach
        </tbody>
    </x-admin.report.table-card>
    <x-admin.report.table-card :title="__('inventory_periodic_cost.effects')" table-id="periodic-cost-effects" class="mb-3">
        <thead><tr><th>{{ __('Account') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Cost Center') }}</th><th>{{ __('Source') }}</th><th>{{ __('inventory_periodic_cost.difference') }}</th></tr></thead><tbody>
        @foreach($plan['effects'] as $effect)@continue($effect['effect'] === 'gl_precision')<tr><td>{{ $effect['account_labels'][$locale] ?? '—' }}</td><td>{{ $effect['branch_label'] }}</td><td>{{ $effect['cost_center_labels'][$locale] ?? '—' }}</td><td>{{ $effect['source_doc_num'] }}</td><td>{{ $numbers->format($effect['amount'], 8) }}</td></tr>@endforeach
        </tbody>
    </x-admin.report.table-card>
    @php($precisionEffects = collect($plan['effects'])->where('effect', 'gl_precision'))
    @if($precisionEffects->isNotEmpty())
    <x-admin.report.table-card :title="__('inventory_periodic_cost.precision')" table-id="periodic-cost-precision" class="mb-3">
        <thead><tr><th>{{ __('Source') }}</th><th>{{ __('Journal Entry') }}</th><th>{{ __('Account') }}</th><th>{{ __('Branch') }}</th><th>{{ __('Cost Center') }}</th><th>{{ __('Cost') }}</th><th>{{ __('inventory_periodic_cost.booked') }}</th><th>{{ __('inventory_periodic_cost.rounded') }}</th><th>{{ __('inventory_periodic_cost.difference') }}</th></tr></thead><tbody>
        @foreach($precisionEffects as $effect)<tr><td>{{ $effect['source_doc_num'] }}</td><td>{{ $journalNumbers[$effect['source_journal_id']] ?? $effect['source_journal_id'] }}</td><td>{{ $effect['account_labels'][$locale] ?? '—' }}</td><td>{{ $effect['branch_label'] }}</td><td>{{ $effect['cost_center_labels'][$locale] ?? '—' }}</td>@foreach(['exact_total_cost','legacy_booked_amount','canonical_rounded_amount','amount'] as $field)<td class="text-nowrap">{{ $numbers->format($effect[$field], 8) }}</td>@endforeach</tr>@endforeach
        </tbody>
    </x-admin.report.table-card>
    @endif
    @if($close->status === \Modules\Inventory\Models\InventoryPeriodicCostClose::StatusPrepared)
    @can('inventory.cost_policies.periodic.approve')<div class="card"><div class="card-body row g-3">
        @if((int) $close->prepared_by !== (int) auth()->id())<div class="col-md-6"><form novalidate method="POST" action="{{ route('admin.inventory.periodic-cost-closes.approve', $close) }}">@csrf<x-forms.label for="periodic_approval" :label="__('inventory_periodic_cost.reference')" :required="true" /><x-forms.input id="periodic_approval" name="approval_reference" :value="old('approval_reference')" maxlength="500" /><button class="btn btn-success mt-2" type="submit">{{ __('inventory_periodic_cost.approve') }}</button></form></div>@endif
        <div class="col-md-6"><form novalidate method="POST" action="{{ route('admin.inventory.periodic-cost-closes.reject', $close) }}">@csrf<x-forms.label for="periodic_rejection" :label="__('inventory_periodic_cost.rejection_reason')" :required="true" /><x-forms.input id="periodic_rejection" name="rejection_reason" :value="old('rejection_reason')" maxlength="2000" /><button class="btn btn-outline-danger mt-2" type="submit">{{ __('inventory_periodic_cost.reject') }}</button></form></div>
    </div></div>@endcan
    @endif
</div>
@endsection
