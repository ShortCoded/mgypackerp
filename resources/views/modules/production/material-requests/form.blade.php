@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $title = $isView
        ? __('production_execution.material_requests.view_document', ['document' => $record->doc_num])
        : ($isEdit ? __('production_execution.material_requests.edit_document', ['document' => $record->doc_num]) : ($isClone ? __('production_execution.material_requests.clone_document', ['document' => $record->doc_num]) : __('production_execution.material_requests.create')));
    $requestLines = $isClone ? collect() : ($record?->lines?->keyBy('production_material_requirement_id') ?? collect());
    $additional = (bool) old('additional', $record?->request_type === 'additional');
    $issuableLines = $record?->lines?->filter(fn ($line) => bccomp(bcsub((string) $line->reserved_quantity, (string) $line->issued_quantity, 8), '0', 8) > 0) ?? collect();
@endphp

@section('title', $title)

@section('content')
    <div class="production-mobile-workflow">
        <form method="POST" action="{{ $isEdit ? route('admin.production.material-requests.update', $record) : route('admin.production.material-requests.store') }}" data-production-material-request-form>
            @csrf
            @if($isEdit) @method('PUT') @endif
            <x-forms.input type="hidden" name="submit_action" value="save" />
            <x-forms.line-item-cards :line-label="__('production_execution.material_requests.line')" />

            <div class="card mb-3">
                <div class="card-header py-2"><div class="row flex-between-center g-2">
                    <div class="col"><h5 class="mb-0">{{ $title }}</h5>@if($record && ! $isClone)<span class="badge badge-subtle-secondary mt-1">{{ __('production_execution.statuses.'.$record->status) }}</span>@endif</div>
                    <div class="col-auto d-flex flex-wrap gap-2">
                        @if($record && ! $isClone) @can('production.material_requests.print')<a class="btn btn-falcon-default btn-sm" target="_blank" rel="noopener" href="{{ route('admin.production.material-requests.print', $record) }}"><span class="fas fa-print me-1"></span>{{ __('common.actions.print') }}</a>@endcan @endif
                        @include('modules.finance.partials.form-actions', ['mode' => $isClone ? 'clone' : $mode, 'record' => $record, 'resource' => 'production.material_requests', 'routePrefix' => 'admin.production.material-requests', 'canEditRecord' => $record?->status === \Modules\Production\Models\ProductionMaterialRequest::StatusSubmitted, 'canDeleteRecord' => $record?->status === \Modules\Production\Models\ProductionMaterialRequest::StatusSubmitted])
                    </div>
                </div></div>

                <div class="card-body">
                    @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                    <fieldset @disabled($isView)>
                        <div class="row g-3 align-items-start">
                            <div class="col-lg-6">
                                <x-forms.label for="production-material-run" :label="__('production_execution.fields.run')" required />
                                @if($isEdit)
                                    <x-forms.input type="hidden" name="production_run_id" :value="$selectedRun?->id" />
                                @endif
                                <x-forms.select variant="local" id="production-material-run" name="production_run_id" required :disabled="$isEdit || $isView" data-material-run-select data-navigation-url="{{ route('admin.production.material-requests.create') }}">
                                    <option value="">{{ __('common.placeholders.select') }}</option>
                                    @foreach($runs as $run)
                                        <option value="{{ $run->id }}" @selected((int) old('production_run_id', $selectedRun?->id) === (int) $run->id)>{{ $run->run_number }} — {{ $run->stageSnapshot?->stage_name ?: $run->product?->name }}</option>
                                    @endforeach
                                </x-forms.select>
                            </div>
                            <div class="col-lg-3"><x-forms.label for="production-material-store" :label="__('production_execution.fields.store')" required /><x-forms.select variant="local" id="production-material-store" name="branch_store_id" required><option value="">{{ __('common.placeholders.select') }}</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((int) old('branch_store_id', $record?->branch_store_id) === (int) $store->id)>{{ $store->name }}</option>@endforeach</x-forms.select></div>
                            <div class="col-lg-3"><x-forms.label for="production-material-required-by" :label="__('production_execution.fields.required_by')" /><x-forms.date-input id="production-material-required-by" name="required_by_date" :value="old('required_by_date', $record?->required_by_date?->toDateString())" /></div>
                            <div class="col-md-4"><div class="form-check mt-md-4 pt-md-2"><x-forms.input class="form-check-input" type="checkbox" name="additional" value="1" id="production-material-additional" data-additional-material :checked="$additional" /><label class="form-check-label" for="production-material-additional">{{ __('production_execution.material_requests.additional') }}</label></div></div>
                            <div class="col-md-8"><x-forms.label for="production-material-reason" :label="__('production_execution.fields.reason')" :required="$additional" /><x-forms.textarea id="production-material-reason" name="reason" rows="3" maxlength="2000" data-additional-reason :required="$additional">{{ old('reason', $record?->reason) }}</x-forms.textarea></div>
                        </div>

                        <div class="border-top mt-4 pt-3">
                            <h6 class="text-700 mb-2">{{ __('production_execution.material_requests.requirements') }}</h6>
                            @if($selectedRun)
                                <div class="table-responsive" role="region" aria-label="{{ __('production_execution.material_requests.requirements') }}" tabindex="0">
                                    <table class="table table-sm table-hover align-middle mb-0 erp-entry-lines-table"><thead class="bg-100 text-900"><tr><th class="text-center erp-entry-line-number">#</th><th>{{ __('production_execution.fields.product') }}</th><th>{{ __('production_execution.material_requests.planned_issued') }}</th><th>{{ __('production_execution.fields.quantity') }} <span class="text-danger">*</span></th></tr></thead><tbody>
                                        @foreach($selectedRun->requirements as $index => $requirement)
                                            @php
                                                $sourceLine = $requestLines->get($requirement->id);
                                                $remaining = (string) ($remainingRequestableByRequirement->get($requirement->id) ?? '0');
                                                $defaultQuantity = $sourceLine?->requested_quantity ?? (! $additional && bccomp($remaining, '0', 8) > 0 ? $remaining : null);
                                            @endphp
                                            <tr><td class="text-center erp-entry-line-number" data-row-number>{{ $index + 1 }}</td><td><x-forms.input type="hidden" name="lines[{{ $index }}][requirement_id]" value="{{ $requirement->id }}" />{{ $requirement->product?->doc_num }} — {{ $requirement->product?->name }}</td><td dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($requirement->planned_quantity) }} / {{ app(\Modules\Core\Services\NumericFormatService::class)->format($requirement->issued_quantity) }}</td><td><x-forms.numeric-input name="lines[{{ $index }}][quantity]" :value="old('lines.'.$index.'.quantity', $defaultQuantity)" :scale="8" min="0.00000001" step="0.00000001" arrow-step="1" data-planned-remaining="{{ bccomp($remaining, '0', 8) > 0 ? $remaining : '' }}" /></td></tr>
                                        @endforeach
                                    </tbody></table>
                                </div>
                            @else
                                <div class="alert alert-info mb-0">{{ __('production_execution.material_requests.select_run_first') }}</div>
                            @endif
                        </div>
                    </fieldset>

                    @if($record && ! $isClone)
                        <div class="border-top mt-4 pt-3">
                            <h6 class="text-700 mb-2">{{ __('production_execution.material_requests.linked_inventory_documents') }}</h6>
                            @forelse($record->inventoryDocuments as $document)
                                <div class="border rounded-2 p-2 mb-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
                                    <div>
                                        @can('inventory.documents.view')
                                            <a class="fw-semibold" href="{{ route('admin.inventory.documents.show', $document) }}">{{ $document->doc_num }}</a>
                                        @else
                                            <span class="fw-semibold">{{ $document->doc_num }}</span>
                                        @endcan
                                        <span class="text-700 mx-1">—</span>
                                        <span>{{ __('inventory.movements.types.'.$document->document_type) }}</span>
                                        <span class="badge rounded-pill badge-subtle-secondary ms-1">{{ __('inventory.movements.statuses.'.$document->status) }}</span>
                                    </div>
                                    @can('inventory.documents.print')
                                        <a class="btn btn-falcon-default btn-sm" target="_blank" rel="noopener" href="{{ route('admin.inventory.documents.print', $document) }}">
                                            <span class="fas fa-print me-1"></span>{{ __('common.actions.print') }}
                                        </a>
                                    @endcan
                                </div>
                            @empty
                                <div class="alert alert-info mb-0">{{ __('production_execution.material_requests.no_inventory_documents') }}</div>
                            @endforelse
                        </div>
                    @endif
                </div>
                <div class="card-footer d-flex flex-wrap justify-content-between gap-2">
                    <div>@if($record && ! $isClone) @can('production.material_requests.print')<a class="btn btn-falcon-default btn-sm" target="_blank" rel="noopener" href="{{ route('admin.production.material-requests.print', $record) }}"><span class="fas fa-print me-1"></span>{{ __('common.actions.print') }}</a>@endcan @endif</div>
                    @include('modules.finance.partials.form-actions', ['mode' => $isClone ? 'clone' : $mode, 'record' => $record, 'resource' => 'production.material_requests', 'routePrefix' => 'admin.production.material-requests', 'canEditRecord' => $record?->status === \Modules\Production\Models\ProductionMaterialRequest::StatusSubmitted, 'canDeleteRecord' => $record?->status === \Modules\Production\Models\ProductionMaterialRequest::StatusSubmitted])
                </div>
            </div>
        </form>

        @if($isView && $record && $issuableLines->isNotEmpty() && in_array($record->status, [\Modules\Production\Models\ProductionMaterialRequest::StatusApproved, \Modules\Production\Models\ProductionMaterialRequest::StatusShortage, \Modules\Production\Models\ProductionMaterialRequest::StatusPartiallyIssued], true))
            @can('production.material_requests.issue')
                <form method="POST" action="{{ route('admin.production.material-requests.issue', $record) }}">
                    @csrf
                    <div class="card mb-3" id="production-material-partial-issue">
                        <div class="card-header py-2 d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div><h5 class="mb-0">{{ __('production_execution.material_requests.partial_issue') }}</h5><div class="small text-700 mt-1">{{ __('production_execution.material_requests.partial_issue_help') }}</div></div>
                            <button class="btn btn-primary btn-sm" type="submit"><span class="fas fa-dolly me-1"></span>{{ __('production_execution.actions.issue') }}</button>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                @foreach($issuableLines->values() as $index => $line)
                                    @php($remainingReserved = bcsub((string) $line->reserved_quantity, (string) $line->issued_quantity, 8))
                                    <div class="col-12 col-lg-6">
                                        <div class="border rounded-2 p-3 h-100">
                                            <x-forms.input type="hidden" name="lines[{{ $index }}][request_line_id]" :value="$line->id" />
                                            <div class="fw-semibold mb-2">{{ $line->product?->doc_num }} — {{ $line->product?->name }}</div>
                                            <div class="small text-700 mb-2">{{ __('production_execution.material_requests.remaining_reserved') }}: <span dir="ltr">{{ app(\Modules\Core\Services\NumericFormatService::class)->format($remainingReserved) }} {{ $line->unit?->name }}</span></div>
                                            <x-forms.label :for="'production-material-issue-'.$line->id" :label="__('production_execution.fields.quantity')" required />
                                            <x-forms.numeric-input :id="'production-material-issue-'.$line->id" name="lines[{{ $index }}][quantity]" :value="$remainingReserved" :scale="8" min="0" :max="$remainingReserved" step="0.00000001" arrow-step="1" required />
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-end">
                            <button class="btn btn-primary" type="submit"><span class="fas fa-dolly me-1"></span>{{ __('production_execution.actions.issue') }}</button>
                        </div>
                    </div>
                </form>
            @endcan
        @endif
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
