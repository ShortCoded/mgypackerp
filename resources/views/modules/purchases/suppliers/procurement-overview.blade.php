@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@if($procurementOverview)
<section class="card mb-3">
    <div class="card-header"><h5 class="mb-0">{{ __('Supplier activity') }}</h5></div>
    <div class="card-body">
        @if($procurementOverview['open_orders'] !== null)<p>{{ __('Open purchase orders') }}: <strong>{{ $procurementOverview['open_orders'] }}</strong></p>@endif
        @foreach($procurementOverview['metrics'] as $metric)
        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3"><div class="text-600">{{ __('Total purchases') }} · {{ $metric['currency'] }}</div><strong>{{ $numbers->format($metric['purchases']) }}</strong></div>
            <div class="col-12 col-md-6 col-xl-3"><div class="text-600">{{ __('Outstanding balance') }} · {{ $metric['currency'] }}</div><strong>{{ $numbers->format($metric['outstanding']) }}</strong></div>
            <div class="col-12 col-md-6 col-xl-3"><div class="text-600">{{ __('Overdue balance') }} · {{ $metric['currency'] }}</div><strong>{{ $numbers->format($metric['overdue']) }}</strong></div>
            <div class="col-12 col-md-6 col-xl-3"><div class="text-600">{{ __('Last purchase date') }}</div><strong>{{ $metric['last_purchase_date'] ?: '—' }}</strong></div>
        </div>
        @endforeach
        @can('reports.purchases.view')
        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach(['supplier_statement', 'purchase_ledger', 'price_history', 'supplier_aging'] as $type)
                @if(auth()->user()?->can('purchases.prices.view'))<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.procurement-cycle-report.index', ['report_type' => $type, 'supplier_doc_num' => $procurementOverview['supplier_doc_num']]) }}">{{ __('procurement.reports.types.'.$type) }}</a>@endif
            @endforeach
        </div>
        @endcan
        @if($procurementOverview['cheques']->isNotEmpty())
        <h6>{{ __('Cheques') }}</h6>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Cheque number') }}</th><th>{{ __('Bank') }}</th><th>{{ __('Due date') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
        @foreach($procurementOverview['cheques'] as $cheque)<tr><td><a href="{{ route('admin.finance.cheques.show', $cheque) }}">{{ $cheque->doc_num }}</a></td><td>{{ $cheque->cheque_number }}</td><td>{{ $cheque->bankAccount?->account_name }}</td><td>{{ $cheque->due_date?->format('Y-m-d') }}</td><td>{{ __('cheques.statuses.'.$cheque->status) }}</td></tr>@endforeach
        </tbody></table></div>
        @endif
        @if($procurementOverview['attachments']->isNotEmpty())
        <h6>{{ __('Attachments') }}</h6><ul>
            @foreach($procurementOverview['attachments'] as $usage)<li>@can('file_manager.download')<a href="{{ route('admin.file-manager.files.download', $usage->file->doc_num) }}">{{ $usage->file->original_name }}</a>@else{{ $usage->file->original_name }}@endcan</li>@endforeach
        </ul>
        @endif
        <div class="accordion" id="supplier-procurement-activity">
        @foreach($procurementOverview['sections'] as $section)
            <div class="accordion-item">
                <h6 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#supplier-documents-{{ $loop->index }}">{{ __($section['label']) }}</button></h6>
                <div id="supplier-documents-{{ $loop->index }}" class="accordion-collapse collapse"><div class="accordion-body">
                    <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>
                    @forelse($section['records'] as $document)
                        <tr><td><a href="{{ route($section['route'], $document->doc_num) }}">{{ $document->doc_num }}</a></td><td>{{ ($document->document_date ?? $document->quotation_date ?? $document->invoice_date ?? $document->return_date ?? $document->payment_date)?->format('Y-m-d') }}</td><td>{{ __('procurement.statuses.'.$document->status) }}</td></tr>
                    @empty<tr><td colspan="3">{{ __('No matching records.') }}</td></tr>@endforelse
                    </tbody></table></div>
                </div></div>
            </div>
        @endforeach
        </div>
    </div>
</section>
@endif
