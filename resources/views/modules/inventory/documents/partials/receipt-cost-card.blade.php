@php
    $receiptCostCompletion = $receiptCostCompletion ?? false;
    $receiptCostRoutePrefix = $receiptCostCompletion ? "admin.inventory.cost-completions." : "admin.inventory.documents.";
@endphp
@if($record->document_type === \Modules\Inventory\Models\InventoryDocument::TypeReceipt)
    @php
        $canPriceReceipt = $record->status === \Modules\Inventory\Models\InventoryDocument::StatusPosted
            && $record->source_document_type === null
            && $record->production_order_id === null
            && $record->production_run_id === null
            && $record->production_run_batch_id === null
            && $record->journal_entry_id === null
            && $record->lines->isNotEmpty()
            && $record->lines->every(fn ($line) => $line->unit_cost === null && $line->total_cost === null);
        $pendingReceiptCostProposal = $record->costProposals->firstWhere('status', \Modules\Inventory\Models\InventoryReceiptCostProposal::StatusPending);
    @endphp
    <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">{{ __('inventory.movements.receipt_pricing_title') }}</h6></div>
        <div class="card-body">
            @if($errors->any())
                <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            @endif
            @if($pendingReceiptCostProposal)
                <div class="alert alert-info">{{ __('inventory.movements.receipt_pricing_pending_review') }} #{{ $pendingReceiptCostProposal->revision }} · {{ __('inventory.movements.receipt_pricing_basis_'.$pendingReceiptCostProposal->basis) }} · {{ $pendingReceiptCostProposal->preparedBy?->name }}</div>
                @if($pendingReceiptCostProposal->basis_note)<p>{{ $pendingReceiptCostProposal->basis_note }}</p>@endif
                <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('inventory.movements.fields.product') }}</th><th>{{ __('inventory.movements.fields.quantity') }}</th><th>{{ __('inventory.movements.fields.unit_cost') }}</th></tr></thead><tbody>
                    @foreach($pendingReceiptCostProposal->line_snapshot as $entry)
                        <tr><td>{{ $entry['product_code'] }}</td><td>{{ $numbers->format($entry['quantity']) }}</td><td>{{ $numbers->format($entry['unit_cost'], 8) }}</td></tr>
                    @endforeach
                </tbody></table></div>
                @if($pendingReceiptCostProposal->impact_snapshot)
                    @include('modules.inventory.documents.partials.receipt-cost-impact', ['impact' => $pendingReceiptCostProposal->impact_snapshot])
                @endif
                @can('inventory.documents.approve_receipt_cost')
                    @if($pendingReceiptCostProposal->source_file_path)<a class="btn btn-falcon-default btn-sm mb-3" href="{{ route($receiptCostRoutePrefix.'receipt-cost-source', [$record, $pendingReceiptCostProposal]) }}">{{ __('inventory.movements.receipt_pricing_source_download') }}</a>@endif
                    @if((int) $pendingReceiptCostProposal->prepared_by !== (int) auth()->id())
                        <form novalidate method="POST" action="{{ route($receiptCostRoutePrefix.'receipt-cost-approve', [$record, $pendingReceiptCostProposal]) }}" class="row g-2 mb-3">
                            @csrf
                            <div class="col-md-5"><x-forms.label for="receipt-cost-source-reference" :label="__('inventory.movements.receipt_pricing_reference')" required /><x-forms.input id="receipt-cost-source-reference" name="source_reference" :value="$pendingReceiptCostProposal->source_reference ?? old('source_reference')" :readonly="$pendingReceiptCostProposal->source_reference !== null" /></div>
                            <div class="col-md-5"><x-forms.label for="receipt-cost-approval-reference" :label="__('inventory.movements.receipt_pricing_approval_reference')" required /><x-forms.input id="receipt-cost-approval-reference" name="approval_reference" :value="old('approval_reference')" /></div>
                            <div class="col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-primary btn-sm">{{ __('inventory.movements.receipt_pricing_approve') }}</button></div>
                        </form>
                    @endif
                    <form novalidate method="POST" action="{{ route($receiptCostRoutePrefix.'receipt-cost-reject', [$record, $pendingReceiptCostProposal]) }}" class="d-flex flex-wrap gap-2 align-items-end">
                        @csrf
                        <div><x-forms.label for="receipt-cost-rejection-reason" :label="__('inventory.movements.receipt_pricing_rejection_reason')" required /><x-forms.input id="receipt-cost-rejection-reason" name="reason" :value="old('reason')" /></div>
                        <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('inventory.movements.receipt_pricing_reject') }}</button>
                    </form>
                @endcan
            @elseif($canPriceReceipt && auth()->user()?->can('inventory.documents.propose_receipt_cost'))
                <p class="text-muted small">{{ __($receiptCostCompletion ? 'inventory.movements.receipt_completion_entry_help' : 'inventory.movements.receipt_pricing_help') }}</p>
                <a class="btn btn-falcon-default btn-sm mb-3" href="{{ route($receiptCostRoutePrefix.'receipt-cost-template', $record) }}">{{ __('inventory.movements.receipt_pricing_template') }}</a>
                <form novalidate method="POST" enctype="multipart/form-data" action="{{ route($receiptCostRoutePrefix.'price-receipt', $record) }}">
                    @csrf
                    @if($receiptCostCompletion)
                        <div class="row g-3 mb-3">
                            <div class="col-md-4"><x-forms.label for="receipt-cost-posting-date" :label="__('inventory.movements.receipt_completion_posting_date')" required /><x-forms.date-input id="receipt-cost-posting-date" name="posting_date" :value="old('posting_date', now()->toDateString())" /></div>
                            <div class="col-md-8"><x-forms.label for="receipt-cost-counterpart" :label="__('inventory.movements.receipt_completion_counterpart')" required /><x-forms.select id="receipt-cost-counterpart" name="counterpart_account_id" variant="ajax" :url="route('admin.inventory.cost-completions.select2.accounts')" :placeholder="__('common.placeholders.select')"><option value=""></option>@if($selectedCounterpart)<option selected value="{{ $selectedCounterpart->id }}">{{ $selectedCounterpart->codeNameLabel() }}</option>@endif</x-forms.select></div>
                        </div>
                    @endif
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-lg-4">
                            <x-forms.label for="receipt-pricing-reference" :label="__('inventory.movements.receipt_pricing_reference')" />
                            <x-forms.input id="receipt-pricing-reference" name="source_reference" :value="old('source_reference')" maxlength="255" />
                        </div>
                        <div class="col-12 col-lg-4"><x-forms.label for="receipt-cost-basis" :label="__('inventory.movements.receipt_pricing_basis')" required /><x-forms.select variant="local" id="receipt-cost-basis" name="basis" :allow-clear="false"><option value="estimate" @selected(old('basis', 'estimate') === 'estimate')>{{ __('inventory.movements.receipt_pricing_basis_estimate') }}</option><option value="documented" @selected(old('basis') === 'documented')>{{ __('inventory.movements.receipt_pricing_basis_documented') }}</option></x-forms.select></div>
                        <div class="col-12 col-lg-4"><x-forms.label for="receipt-cost-workbook" :label="__('inventory.movements.receipt_pricing_workbook')" /><x-forms.input type="file" accept=".xlsx" id="receipt-cost-workbook" name="workbook" /></div>
                        <div class="col-12"><x-forms.label for="receipt-cost-basis-note" :label="__('inventory.movements.receipt_pricing_basis_note')" /><x-forms.input id="receipt-cost-basis-note" name="basis_note" :value="old('basis_note')" /></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>{{ __('inventory.movements.fields.product') }}</th><th>{{ __('inventory.movements.fields.quantity') }}</th><th>{{ __('inventory.movements.fields.unit_cost') }}</th></tr></thead>
                            <tbody>
                                @foreach($record->lines as $line)
                                    <tr>
                                        <td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td>
                                        <td>{{ $numbers->format($line->quantity) }}</td>
                                        <td style="min-width: 11rem"><x-forms.numeric-input :name="'unit_costs['.$line->getKey().']'" :value="old('unit_costs.'.$line->getKey())" :scale="8" min="0.00000001" step="0.00000001" /></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-primary btn-sm" type="submit">{{ __('inventory.movements.receipt_pricing_submit') }}</button>
                </form>
            @else
                @if($receiptCostCompletion)
                    <h6>{{ __('inventory.movements.receipt_completion_original_costs') }}</h6>
                @endif
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>{{ __('inventory.movements.fields.product') }}</th><th>{{ __('inventory.movements.fields.quantity') }}</th><th>{{ __('inventory.movements.fields.unit_cost') }}</th><th>{{ __('inventory.movements.receipt_pricing_total') }}</th></tr></thead>
                        <tbody>
                            @foreach($record->lines as $line)
                                <tr>
                                    <td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td>
                                    <td>{{ $numbers->format($line->quantity) }}</td>
                                    <td>{{ $line->unit_cost === null ? '—' : $numbers->format($line->unit_cost, 8) }}</td>
                                    <td>{{ $line->total_cost === null ? '—' : $numbers->format($line->total_cost) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(data_get($record->lines->first()?->product_snapshot, 'cost_correction.source_reference'))
                    <div class="small mt-3"><strong>{{ __('inventory.movements.receipt_pricing_reference') }}:</strong> {{ data_get($record->lines->first()->product_snapshot, 'cost_correction.source_reference') }}</div>
                @endif
                @if($record->lines->contains(fn ($line) => data_get($line->product_snapshot, 'cost_correction.basis') === 'local_provisional'))
                    <div class="alert alert-warning mt-3 mb-0">{{ __('inventory.movements.receipt_pricing_provisional_notice') }}</div>
                @endif
            @endif
            @foreach($record->costProposals->where('status', \Modules\Inventory\Models\InventoryReceiptCostProposal::StatusApproved) as $completedProposal)
                @if($completedProposal->impact_snapshot)
                    <div class="alert alert-success mt-3">{{ __('inventory.movements.receipt_completion_approved') }} · {{ $completedProposal->approval_reference }} · {{ $completedProposal->approvedBy?->name }}</div>
                    <h6>{{ __('inventory.movements.receipt_completion_approved_costs') }} #{{ $completedProposal->revision }}</h6>
                    <div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>{{ __('inventory.movements.fields.product') }}</th><th>{{ __('inventory.movements.fields.quantity') }}</th><th>{{ __('inventory.movements.fields.unit_cost') }}</th><th>{{ __('inventory.movements.receipt_pricing_total') }}</th></tr></thead><tbody>
                        @foreach($completedProposal->line_snapshot as $entry)
                            <tr><td>{{ $entry['product_code'] }}</td><td>{{ $numbers->format($entry['quantity']) }}</td><td dir="ltr">{{ $numbers->format($entry['unit_cost'], 8) }}</td><td dir="ltr">{{ $numbers->format(bcmul($entry['quantity'], $entry['unit_cost'], 8), 8) }}</td></tr>
                        @endforeach
                    </tbody></table></div>
                    @if($completedProposal->valueAdjustment?->journalEntry)
                        <p>{{ __('Journal Entry') }}:
                            @can('journal_entries.view')
                                <a href="{{ route('admin.accounting.journal-entries.show', $completedProposal->valueAdjustment->journalEntry) }}">{{ $completedProposal->valueAdjustment->journalEntry->doc_num }}</a>
                            @else
                                {{ $completedProposal->valueAdjustment->journalEntry->doc_num }}
                            @endcan
                        </p>
                    @endif
                    @include('modules.inventory.documents.partials.receipt-cost-impact', ['impact' => $completedProposal->impact_snapshot])
                @endif
            @endforeach
        </div>
    </div>
@endif
