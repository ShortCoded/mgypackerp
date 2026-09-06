<div class="card mb-3" id="ledger">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <h6 class="mb-0">{{ __('fixed_assets.cycle.ledger') }}</h6>
        <select class="form-select w-auto js-asset-ledger-type" aria-label="{{ __('fixed_assets.cycle.type') }}"><option value="">{{ __('fixed_assets.product.all_movements') }}</option>@foreach(\Modules\FixedAssets\Services\FixedAssetLedgerService::types() as $type)<option value="{{ $type }}">{{ __('fixed_assets.cycle.'.$type) }}</option>@endforeach</select>
    </div>
    <div class="card-body table-responsive"><table class="table table-sm align-middle"><thead><tr>
        @foreach(['date', 'type', 'document', 'amount', 'user', 'journal_entry', 'status'] as $column)<th>{{ $column === 'journal_entry' ? __('fixed_assets.reports.columns.journal_entry') : __('fixed_assets.cycle.'.$column) }}</th>@endforeach<th>{{ __('common.fields.actions') }}</th>
    </tr></thead><tbody>
    @foreach($ledger as $row)
        @php($movement = $asset->movements->firstWhere('doc_num', $row['document']))
        <tr data-movement-type="{{ $row['type'] }}" @if($movement && !str_ends_with($row['type'], '_reversal')) id="movement-{{ $row['document'] }}" @endif>
            <td class="text-nowrap">{{ $dates->formatDate($row['date'], '') }}</td>
            <td><span class="badge badge-subtle-{{ str_ends_with($row['type'], '_reversal') ? 'warning' : 'info' }}">{{ __('fixed_assets.cycle.'.$row['type']) }}</span><div class="small text-600 mt-1">{{ $row['detail'] }}</div></td>
            <td><a href="{{ $row['url'] }}">{{ $row['document'] }}</a></td>
            <td dir="ltr">{{ $row['amount'] === null ? '—' : $numbers->format($row['amount']) }}</td><td>{{ $row['user'] }}</td>
            <td>@if($row['journal'])@can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $row['journal']) }}">{{ $row['journal']->doc_num }}</a>@else {{ $row['journal']->doc_num }} @endcan @endif</td>
            <td><span class="badge badge-subtle-{{ $row['reversed'] ? 'warning' : 'success' }}">{{ $row['type'] === 'creation' ? __('fixed_assets.cycle.creation') : ($row['reversed'] ? __('fixed_assets.lifecycle.statuses.reversed') : __('fixed_assets.lifecycle.statuses.posted')) }}</span></td>
            <td>@if($movement && !str_ends_with($row['type'], '_reversal'))
                @can('fixed_assets.print')<a target="_blank" href="{{ route('admin.fixed-assets.prints.movement', $movement) }}">{{ __('common.actions.print') }}</a>@endcan
                @can($movement->movement_type === 'addition' ? 'fixed_assets.improvement.reverse' : 'fixed_assets.recognition.reverse')
                @if(app(\Modules\FixedAssets\Services\FixedAssetCostMovementService::class)->canReverse($movement))<details class="mt-2"><summary class="text-danger">{{ __('fixed_assets.lifecycle.reverse') }}</summary><form method="POST" action="{{ route('admin.fixed-assets.movements.reverse', $movement) }}">@csrf<input class="form-control form-control-sm" name="reason" required aria-label="{{ __('fixed_assets.lifecycle.reversal_reason') }}" placeholder="{{ __('fixed_assets.lifecycle.reversal_reason') }}"><button class="btn btn-sm btn-falcon-danger mt-1">{{ __('fixed_assets.lifecycle.reverse') }}</button></form></details>@endif
                @endcan
            @endif</td>
        </tr>
    @endforeach
    </tbody></table></div>
</div>
