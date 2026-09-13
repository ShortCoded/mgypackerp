<div class="card mb-3" id="ledger">
    <div class="card-header py-2 d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <h6 class="mb-0">{{ __('fixed_assets.cycle.ledger') }}</h6>
        <x-forms.select class="form-select w-auto js-asset-ledger-type" aria-label="{{ __('fixed_assets.cycle.type') }}"><option value="">{{ __('fixed_assets.product.all_movements') }}</option>@foreach(\Modules\FixedAssets\Services\FixedAssetLedgerService::types() as $type)<option value="{{ $type }}">{{ __('fixed_assets.cycle.'.$type) }}</option>@endforeach</x-forms.select>
    </div>
    <div class="d-md-none alert alert-info rounded-0 border-0 mb-0 py-2 small"><span class="fas fa-arrows-alt-h me-1"></span>{{ __('fixed_assets.product.scroll_table_hint') }}</div>
    <div class="card-body p-0 table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr>
        @foreach(['date', 'type', 'document', 'amount', 'user', 'journal_entry', 'status'] as $column)<th>{{ $column === 'journal_entry' ? __('fixed_assets.reports.columns.journal_entry') : __('fixed_assets.cycle.'.$column) }}</th>@endforeach<th>{{ __('common.fields.actions') }}</th>
    </tr></thead><tbody>
    @foreach($ledger as $row)
        @php
            $movement = $asset->movements->firstWhere('doc_num', $row['document']);
            $attachmentType = str_starts_with($row['type'], 'depreciation') ? 'depreciation' : (str_starts_with($row['type'], 'disposal') ? 'disposal' : (in_array($row['type'], ['creation', 'legacy_baseline'], true) ? 'asset' : 'movement'));
            $attachmentTarget = $attachmentTargets->first(fn ($target) => $target['type'] === $attachmentType && $target['document'] === $row['document']);
            $rowAttachments = collect($attachmentTarget['usages'] ?? [])->filter(fn ($usage) => $usage->file);
        @endphp
        <tr data-movement-type="{{ $row['type'] }}" @if($movement && !str_ends_with($row['type'], '_reversal')) id="movement-{{ $row['document'] }}" @endif>
            <td class="text-nowrap">{{ $dates->formatDate($row['date'], '') }}</td>
            <td><span class="badge badge-subtle-{{ str_ends_with($row['type'], '_reversal') ? 'warning' : 'info' }}">{{ __('fixed_assets.cycle.'.$row['type']) }}</span><div class="small text-600 mt-1">{{ $row['detail'] }}</div></td>
            <td><a href="{{ $row['url'] }}">{{ $row['document'] }}</a>
                @if($attachmentTarget && !str_ends_with($row['type'], '_reversal'))
                    @if($rowAttachments->isNotEmpty())
                        <div class="small mt-1"><a class="text-600" href="{{ route('admin.fixed-assets.assets.show', [$asset, 'tab' => 'documents', 'attachment_target' => $attachmentType.'|'.$row['document']]).'#asset-attachments' }}" data-attachment-target="{{ $attachmentType }}|{{ $row['document'] }}"><span class="fas fa-paperclip me-1" aria-hidden="true"></span>{{ __('fixed_assets.product.attachments_count', ['count' => $rowAttachments->count()]) }}</a></div>
                    @else
                        @can('fixed_assets.edit')@can('file_manager.view')<div class="small mt-1"><a class="text-600" href="{{ route('admin.fixed-assets.assets.show', [$asset, 'tab' => 'documents', 'attachment_target' => $attachmentType.'|'.$row['document']]).'#asset-attachments' }}" data-attachment-target="{{ $attachmentType }}|{{ $row['document'] }}"><span class="fas fa-paperclip me-1" aria-hidden="true"></span>{{ __('fixed_assets.product.attach_document') }}</a></div>@endcan @endcan
                    @endif
                @endif
            </td>
            <td dir="ltr">{{ $row['amount'] === null ? '—' : $numbers->format($row['amount']) }}</td><td>{{ $row['user'] }}</td>
            <td>@if($row['journal'])@can('journal_entries.view')<a href="{{ route('admin.accounting.journal-entries.show', $row['journal']) }}">{{ $row['journal']->doc_num }}</a>@else {{ $row['journal']->doc_num }} @endcan @endif</td>
            <td><span class="badge badge-subtle-{{ $row['reversed'] ? 'warning' : 'success' }}">{{ $row['type'] === 'creation' ? __('fixed_assets.cycle.creation') : ($row['reversed'] ? __('fixed_assets.lifecycle.statuses.reversed') : __('fixed_assets.lifecycle.statuses.posted')) }}</span></td>
            <td>@if($movement && !str_ends_with($row['type'], '_reversal'))
                @can('fixed_assets.print')<a target="_blank" href="{{ route('admin.fixed-assets.prints.movement', $movement) }}">{{ __('common.actions.print') }}</a>@endcan
                @can($movement->movement_type === 'addition' ? 'fixed_assets.improvement.reverse' : 'fixed_assets.recognition.reverse')
                @if(app(\Modules\FixedAssets\Services\FixedAssetCostMovementService::class)->canReverse($movement))<details class="mt-2"><summary class="text-danger">{{ __('fixed_assets.lifecycle.reverse') }}</summary><form method="POST" action="{{ route('admin.fixed-assets.movements.reverse', $movement) }}">@csrf<x-forms.input class="form-control form-control-sm" name="reason" required aria-label="{{ __('fixed_assets.lifecycle.reversal_reason') }}" placeholder="{{ __('fixed_assets.lifecycle.reversal_reason') }}" /><button class="btn btn-sm btn-falcon-danger mt-1">{{ __('fixed_assets.lifecycle.reverse') }}</button></form></details>@endif
                @endcan
            @endif</td>
        </tr>
    @endforeach
    </tbody></table></div>
</div>
