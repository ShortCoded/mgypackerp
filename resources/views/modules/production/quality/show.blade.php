@extends('layouts.app')

@section('title', __('production_execution.quality.inspection_details').' — '.$record->doc_num)

@section('content')
    @php
        $lifecycle = [
            ['status' => 'draft', 'label' => __('production_execution.quality.lifecycle.requested'), 'at' => $record->requested_at],
            ['status' => 'received', 'label' => __('production_execution.quality.lifecycle.received'), 'at' => $record->received_at],
            ['status' => 'in_progress', 'label' => __('production_execution.quality.lifecycle.started'), 'at' => $record->started_at],
            ['status' => 'submitted', 'label' => __('production_execution.quality.lifecycle.submitted'), 'at' => $record->submitted_at],
            ['status' => 'reviewed', 'label' => __('production_execution.quality.lifecycle.reviewed'), 'at' => $record->reviewed_at],
            ['status' => 'closed', 'label' => __('production_execution.quality.lifecycle.closed'), 'at' => $record->closed_at],
        ];
    @endphp

    <div class="production-mobile-workflow">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
            <div>
                <a class="small" href="{{ route('admin.production.quality.index') }}">{{ __('production_execution.quality.title') }}</a>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h4 class="mb-0">{{ $record->doc_num }}</h4>
                    @if($record->reinspection_number > 0)<span class="badge badge-subtle-primary">{{ __('production_execution.quality.reinspection_number', ['number' => $record->reinspection_number]) }}</span>@endif
                </div>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <span class="badge rounded-pill badge-subtle-secondary">{{ __('production_execution.statuses.'.$record->status) }}</span>
                    <span class="badge rounded-pill {{ $record->result === 'passed' ? 'badge-subtle-success' : ($record->result === 'failed' ? 'badge-subtle-danger' : 'badge-subtle-warning') }}">{{ __('production_execution.quality_results.'.$record->result) }}</span>
                    @if($record->disposition)<span class="badge rounded-pill badge-subtle-info">{{ __('production_execution.quality_dispositions.'.$record->disposition) }}</span>@endif
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mobile-action-row quality-review-actions" aria-label="{{ __('production_execution.quality.workflow_actions') }}">
                @if ($record->status === \Modules\Production\Models\ProductionQualityInspection::StatusDraft)
                    @can('production.quality.receive')
                        <button class="btn btn-primary" type="button" data-action="post" data-url="{{ route('admin.production.quality.receive', $record->getKey()) }}"><span class="fas fa-inbox me-1"></span>{{ __('production_execution.actions.receive_inspection') }}</button>
                    @endcan
                @elseif ($record->status === \Modules\Production\Models\ProductionQualityInspection::StatusReceived)
                    @can('production.quality.start')
                        <button class="btn btn-primary" type="button" data-action="post" data-url="{{ route('admin.production.quality.start', $record->getKey()) }}"><span class="fas fa-play me-1"></span>{{ __('production_execution.actions.start_inspection') }}</button>
                    @endcan
                @elseif ($record->status === \Modules\Production\Models\ProductionQualityInspection::StatusSubmitted)
                    @can('production.quality.review')
                        <button class="btn btn-success" type="button" data-action="post" data-url="{{ route('admin.production.quality.approve', $record->getKey()) }}"><span class="fas fa-check me-1"></span>{{ __('production_execution.actions.approve') }}</button>
                        <button class="btn btn-outline-danger" type="button" data-action="reason" data-prompt="{{ __('production_execution.quality.review_reason_prompt') }}" data-reason-key="reason" data-url="{{ route('admin.production.quality.reject', $record->getKey()) }}"><span class="fas fa-times me-1"></span>{{ __('production_execution.actions.reject') }}</button>
                    @endcan
                @elseif (in_array($record->status, [\Modules\Production\Models\ProductionQualityInspection::StatusApproved, \Modules\Production\Models\ProductionQualityInspection::StatusRejected], true))
                    @can('production.quality.close')
                        <button class="btn btn-primary" type="button" data-action="post" data-url="{{ route('admin.production.quality.close', $record->getKey()) }}"><span class="fas fa-lock me-1"></span>{{ __('production_execution.actions.close_inspection') }}</button>
                    @endcan
                @elseif ($record->status === \Modules\Production\Models\ProductionQualityInspection::StatusClosed)
                    @can('production.quality.reinspect')
                        <button class="btn btn-outline-primary" type="button" data-action="post" data-url="{{ route('admin.production.quality.reinspect', $record->getKey()) }}"><span class="fas fa-redo me-1"></span>{{ __('production_execution.actions.reinspect') }}</button>
                    @endcan
                @endif
            </div>
        </div>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <div class="quality-lifecycle mb-3" aria-label="{{ __('production_execution.quality.lifecycle.title') }}">
            @foreach($lifecycle as $step)
                <div class="quality-lifecycle-step {{ $step['at'] ? 'is-complete' : '' }}">
                    <span class="quality-lifecycle-marker" aria-hidden="true"><span class="fas {{ $step['at'] ? 'fa-check' : 'fa-circle' }}"></span></span>
                    <span class="quality-lifecycle-label">{{ $step['label'] }}</span>
                    <small>{{ $step['at']?->format('Y-m-d H:i') ?? '—' }}</small>
                </div>
            @endforeach
        </div>

        <div class="quality-summary-grid mb-3">
            <div class="quality-summary-item"><span>{{ __('production_execution.fields.requested_at') }}</span><strong>{{ $record->requested_at?->format('Y-m-d H:i') ?? '—' }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('production_execution.fields.sampled_at') }}</span><strong>{{ $record->sampled_at?->format('Y-m-d H:i') ?? '—' }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('production_execution.fields.inspection_subject') }}</span><strong>{{ __('production_execution.quality_subjects.'.$record->subject_type) }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('production_execution.fields.run') }}</span><strong>@if($record->run)<a href="{{ route('admin.production.runs.show', $record->run) }}">{{ $record->run->run_number }}</a>@else—@endif</strong></div>
            <div class="quality-summary-item"><span>{{ __('production_execution.fields.product') }}</span><strong>{{ $record->run?->product?->name ?? $record->product?->name ?? '—' }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('production_execution.fields.store') }}</span><strong>{{ $record->branchStore?->name ?? '—' }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('production_execution.fields.stock_status') }}</span><strong>{{ $record->stock_status ? __('production_execution.stock_statuses.'.$record->stock_status) : '—' }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('production_execution.fields.batch_lot') }}</span><strong>{{ $record->batch_lot ?: '—' }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('production_execution.fields.stage') }}</span><strong>{{ $record->stageSnapshot?->stage_name ?? '—' }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('production_execution.fields.inspection_type') }}</span><strong>{{ $record->qualityType?->name ?? __('production_execution.quality.general_stage_inspection') }}</strong></div>
            <div class="quality-summary-item"><span>{{ __('production_execution.fields.affected_quantity') }}</span><strong>{{ $record->affected_base_quantity ?? '—' }}</strong></div>
        </div>

        @if ($record->status === \Modules\Production\Models\ProductionQualityInspection::StatusInProgress)
            @can('production.quality.report')
                <form class="card mb-3" method="POST" action="{{ route('admin.production.quality.reports.store', $record->getKey()) }}" enctype="multipart/form-data" data-quality-upload>
                    @csrf
                    <div class="card-header"><h5 class="mb-0">{{ __('production_execution.quality.add_daily_report') }}</h5><p class="small text-600 mb-0">{{ __('production_execution.quality.add_daily_report_help') }}</p></div>
                    <div class="card-body"><div class="row g-3">
                        <div class="col-12 col-md-4"><label class="form-label" for="quality-report-at">{{ __('production_execution.fields.reported_at') }}</label><x-forms.date-input id="quality-report-at" name="reported_at" enable-time :value="old('reported_at', now()->format('Y-m-d H:i'))" required /></div>
                        <div class="col-12 col-md-4"><label class="form-label" for="quality-report-result">{{ __('production_execution.fields.result') }}</label><x-forms.select id="quality-report-result" name="result" required>@foreach(['pending', 'passed', 'failed', 'conditional'] as $result)<option value="{{ $result }}" @selected(old('result', 'pending') === $result)>{{ __('production_execution.quality_results.'.$result) }}</option>@endforeach</x-forms.select></div>
                        <div class="col-12 col-md-4"><label class="form-label" for="quality-report-disposition">{{ __('production_execution.fields.disposition') }}</label><x-forms.select id="quality-report-disposition" name="disposition"><option value="">—</option>@foreach(['release', 'hold', 'rework', 'scrap', 'return'] as $disposition)<option value="{{ $disposition }}" @selected(old('disposition') === $disposition)>{{ __('production_execution.quality_dispositions.'.$disposition) }}</option>@endforeach</x-forms.select></div>
                        <div class="col-12 col-md-4"><label class="form-label" for="quality-report-defect">{{ __('production_execution.fields.defect_code') }}</label><x-forms.input id="quality-report-defect" name="defect_code" :value="old('defect_code')" /></div>
                        <div class="col-12 col-md-4"><label class="form-label" for="quality-report-quantity">{{ __('production_execution.fields.affected_quantity') }}</label><x-forms.input id="quality-report-quantity" name="affected_base_quantity" type="number" min="0" step="0.00000001" inputmode="decimal" :value="old('affected_base_quantity')" /></div>
                        <div class="col-12"><label class="form-label" for="quality-report-observations">{{ __('production_execution.fields.observations') }}</label><x-forms.textarea id="quality-report-observations" name="observations" rows="3" required>{{ old('observations') }}</x-forms.textarea></div>
                        <div class="col-12"><label class="form-label" for="quality-report-corrective-action">{{ __('production_execution.fields.corrective_action') }}</label><x-forms.textarea id="quality-report-corrective-action" name="corrective_action" rows="3">{{ old('corrective_action') }}</x-forms.textarea></div>
                        <div class="col-12">
                            <label class="form-label">{{ __('production_execution.fields.attachments') }}</label>
                            <div class="quality-capture-actions"><label class="btn btn-outline-primary mb-0" for="quality-report-camera"><span class="fas fa-camera me-1"></span>{{ __('production_execution.quality.take_photo') }}</label><x-forms.input class="visually-hidden" id="quality-report-camera" name="evidence_files[]" type="file" accept="image/*" capture="environment" data-quality-evidence data-empty-label="{{ __('production_execution.quality.no_attachments_selected') }}" data-selected-label="{{ __('production_execution.quality.attachments_ready') }}" /><label class="btn btn-outline-secondary mb-0" for="quality-report-files"><span class="fas fa-paperclip me-1"></span>{{ __('production_execution.quality.choose_files') }}</label><x-forms.input class="visually-hidden" id="quality-report-files" name="evidence_files[]" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple data-quality-evidence data-empty-label="{{ __('production_execution.quality.no_attachments_selected') }}" data-selected-label="{{ __('production_execution.quality.attachments_ready') }}" /></div>
                            <div class="small text-600 mt-2" aria-live="polite" data-quality-evidence-summary>{{ __('production_execution.quality.no_attachments_selected') }}</div><div class="quality-evidence-preview" data-quality-evidence-preview></div>
                        </div>
                    </div></div>
                    <div class="card-footer d-grid d-sm-flex justify-content-sm-end"><button class="btn btn-primary" type="submit"><span class="fas fa-file-medical me-1"></span>{{ __('production_execution.actions.add_report') }}</button></div>
                </form>
            @endcan

            @can('production.quality.submit')
                <form class="card mb-3" method="POST" action="{{ route('admin.production.quality.submit', $record->getKey()) }}" enctype="multipart/form-data" data-quality-upload>
                    @csrf
                    <div class="card-header"><h5 class="mb-0">{{ __('production_execution.quality.final_decision') }}</h5><p class="small text-600 mb-0">{{ __('production_execution.quality.final_decision_help') }}</p></div>
                    <div class="card-body">
                        @if($checkpoints->isNotEmpty())
                            <div class="quality-checkpoint-list mb-3">
                                @foreach($checkpoints as $index => $checkpoint)
                                    <fieldset class="quality-checkpoint">
                                        <legend>{{ app()->getLocale() === 'ar' && filled($checkpoint->name_ar) ? $checkpoint->name_ar : $checkpoint->name }} @if($checkpoint->is_required)<span class="text-danger">*</span>@endif</legend>
                                        @if($checkpoint->acceptance_criteria)<p class="small text-600 mb-2">{{ $checkpoint->acceptance_criteria }}</p>@endif
                                        <x-forms.input type="hidden" name="results[{{ $index }}][quality_checkpoint_id]" :value="$checkpoint->id" />
                                        <div class="row g-2">
                                            <div class="col-12 col-md-4">
                                                <label class="form-label" for="checkpoint-result-{{ $checkpoint->id }}">{{ __('production_execution.fields.result') }}</label>
                                                <x-forms.select id="checkpoint-result-{{ $checkpoint->id }}" name="results[{{ $index }}][result]" :required="$checkpoint->is_required">
                                                    @if($checkpoint->is_required)<option value="">{{ __('common.actions.select') }}</option>@endif
                                                    @foreach(['passed', 'failed', 'conditional', 'pending'] as $result)
                                                        <option value="{{ $result }}" @selected(old("results.$index.result", $checkpoint->is_required ? '' : 'pending') === $result)>{{ __('production_execution.quality_results.'.$result) }}</option>
                                                    @endforeach
                                                </x-forms.select>
                                            </div>
                                            @if($checkpoint->response_type === 'numeric')
                                                <div class="col-12 col-md-4">
                                                    <label class="form-label" for="checkpoint-value-{{ $checkpoint->id }}">{{ __('production_execution.fields.measured_value') }} @if($checkpoint->measurement_unit)({{ $checkpoint->measurement_unit }})@endif</label>
                                                    <x-forms.input id="checkpoint-value-{{ $checkpoint->id }}" name="results[{{ $index }}][measured_value]" :value='old("results.$index.measured_value")' :required="$checkpoint->is_required" inputmode="decimal" />
                                                </div>
                                            @endif
                                            <div class="col-12 {{ $checkpoint->response_type === 'numeric' ? 'col-md-4' : 'col-md-8' }}">
                                                <label class="form-label" for="checkpoint-notes-{{ $checkpoint->id }}">{{ __('production_execution.fields.notes') }}</label>
                                                <x-forms.input id="checkpoint-notes-{{ $checkpoint->id }}" name="results[{{ $index }}][notes]" :value='old("results.$index.notes")' />
                                            </div>
                                        </div>
                                    </fieldset>
                                @endforeach
                            </div>
                        @endif

                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="quality-overall-result">{{ __('production_execution.quality.overall_result') }}</label>
                                <x-forms.select id="quality-overall-result" name="result" required data-quality-overall-result>
                                    @foreach(['passed', 'failed', 'conditional'] as $result)<option value="{{ $result }}" @selected(old('result') === $result)>{{ __('production_execution.quality_results.'.$result) }}</option>@endforeach
                                </x-forms.select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="quality-disposition">{{ __('production_execution.fields.disposition') }}</label>
                                <x-forms.select id="quality-disposition" name="disposition" required data-quality-disposition>
                                    @foreach(['release', 'hold', 'rework', 'scrap', 'return'] as $disposition)<option value="{{ $disposition }}" @selected(old('disposition', 'release') === $disposition)>{{ __('production_execution.quality_dispositions.'.$disposition) }}</option>@endforeach
                                </x-forms.select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="quality-defect-code">{{ __('production_execution.fields.defect_code') }}</label>
                                <x-forms.input id="quality-defect-code" name="defect_code" :value="old('defect_code')" />
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="quality-affected-base-quantity">{{ __('production_execution.fields.affected_quantity') }}</label>
                                <x-forms.input id="quality-affected-base-quantity" name="affected_base_quantity" type="number" min="0" step="0.00000001" inputmode="decimal" :value="old('affected_base_quantity', $record->affected_base_quantity)" />
                            </div>
                            <div class="col-12" data-quality-exception-details>
                                <div class="row g-3">
                                    <div class="col-12 col-md-6"><label class="form-label" for="quality-corrective-action">{{ __('production_execution.fields.corrective_action') }}</label><x-forms.textarea id="quality-corrective-action" name="corrective_action" rows="3">{{ old('corrective_action') }}</x-forms.textarea></div>
                                    <div class="col-12 col-md-6"><label class="form-label" for="quality-rework-notes">{{ __('production_execution.fields.rework_notes') }}</label><x-forms.textarea id="quality-rework-notes" name="rework_notes" rows="3">{{ old('rework_notes') }}</x-forms.textarea></div>
                                </div>
                            </div>
                            <div class="col-12"><label class="form-label" for="quality-notes">{{ __('production_execution.fields.notes') }}</label><x-forms.textarea id="quality-notes" name="notes" rows="3">{{ old('notes', $record->notes) }}</x-forms.textarea></div>
                            <div class="col-12">
                                <label class="form-label">{{ __('production_execution.fields.attachments') }}</label>
                                <div class="quality-capture-actions">
                                    <label class="btn btn-outline-primary mb-0" for="quality-camera"><span class="fas fa-camera me-1"></span>{{ __('production_execution.quality.take_photo') }}</label>
                                    <x-forms.input class="visually-hidden" id="quality-camera" name="evidence_files[]" type="file" accept="image/*" capture="environment" data-quality-evidence data-empty-label="{{ __('production_execution.quality.no_attachments_selected') }}" data-selected-label="{{ __('production_execution.quality.attachments_ready') }}" />
                                    <label class="btn btn-outline-secondary mb-0" for="quality-files"><span class="fas fa-paperclip me-1"></span>{{ __('production_execution.quality.choose_files') }}</label>
                                    <x-forms.input class="visually-hidden" id="quality-files" name="evidence_files[]" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple data-quality-evidence data-empty-label="{{ __('production_execution.quality.no_attachments_selected') }}" data-selected-label="{{ __('production_execution.quality.attachments_ready') }}" />
                                </div>
                                <div class="small text-600 mt-2" aria-live="polite" data-quality-evidence-summary>{{ __('production_execution.quality.no_attachments_selected') }}</div>
                                <div class="quality-evidence-preview" data-quality-evidence-preview></div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer d-grid d-sm-flex justify-content-sm-end"><button class="btn btn-primary" type="submit"><span class="fas fa-check-circle me-1"></span>{{ __('production_execution.actions.submit_inspection') }}</button></div>
                </form>
            @endcan
        @endif

        <div class="card mb-3">
            <div class="card-header d-flex align-items-center justify-content-between"><h5 class="mb-0">{{ __('production_execution.quality.daily_reports') }}</h5><span class="badge badge-subtle-primary">{{ $record->reports->count() }}</span></div>
            @if($record->reports->isEmpty())
                <div class="card-body text-600">{{ __('production_execution.quality.no_daily_reports') }}</div>
            @else
                <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>#</th><th>{{ __('production_execution.fields.reported_at') }}</th><th>{{ __('production_execution.fields.result') }}</th><th>{{ __('production_execution.fields.observations') }}</th><th>{{ __('production_execution.fields.corrective_action') }}</th><th>{{ __('production_execution.fields.attachments') }}</th><th>{{ __('production_execution.fields.submitted_by') }}</th></tr></thead><tbody>
                    @foreach($record->reports as $report)
                        <tr><td>{{ $report->sequence }}</td><td>{{ $report->reported_at?->format('Y-m-d H:i') }}</td><td><span class="badge rounded-pill {{ $report->result === 'passed' ? 'badge-subtle-success' : ($report->result === 'failed' ? 'badge-subtle-danger' : 'badge-subtle-warning') }}">{{ __('production_execution.quality_results.'.$report->result) }}</span></td><td class="text-break">{{ $report->observations }}</td><td class="text-break">{{ $report->corrective_action ?: '—' }}</td><td><div class="d-flex flex-wrap gap-1">@foreach(($report->evidence ?? []) as $index => $file)<a class="btn btn-sm btn-falcon-default" href="{{ route('admin.production.quality.reports.evidence', [$record->getKey(), $report->getKey(), $index]) }}" target="_blank">{{ $index + 1 }}</a>@endforeach @if(empty($report->evidence))—@endif</div></td><td>{{ $report->submittedBy?->name ?? '—' }}</td></tr>
                    @endforeach
                </tbody></table></div>
            @endif
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <div class="card h-100">
                    <div class="card-header"><h5 class="mb-0">{{ __('production_execution.quality.checkpoint_results') }}</h5></div>
                    @if ($record->results->isEmpty())
                        <div class="card-body text-600">{{ __('production_execution.quality.no_checkpoint_results') }}</div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead><tr><th>#</th><th>{{ __('production_execution.fields.name') }}</th><th>{{ __('production_execution.fields.result') }}</th><th>{{ __('production_execution.fields.measured_value') }}</th><th>{{ __('production_execution.fields.notes') }}</th></tr></thead>
                                <tbody>
                                    @foreach ($record->results as $result)
                                        @php($checkpoint = $checkpointNames->get($result->quality_checkpoint_id))
                                        <tr>
                                            <td>{{ $result->sequence }}</td>
                                            <td><div class="fw-semibold">{{ app()->getLocale() === 'ar' && filled($checkpoint?->name_ar) ? $checkpoint->name_ar : ($checkpoint?->name ?? '#'.$result->quality_checkpoint_id) }}</div>@if($checkpoint?->acceptance_criteria)<div class="small text-600">{{ $checkpoint->acceptance_criteria }}</div>@endif</td>
                                            <td><span class="badge rounded-pill badge-subtle-secondary">{{ __('production_execution.quality_results.'.$result->result) }}</span></td>
                                            <td>{{ $result->measured_value ?: '—' }} {{ $checkpoint?->measurement_unit }}</td>
                                            <td>{{ $result->notes ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            <div class="col-12 col-xl-5">
                <div class="card h-100">
                    <div class="card-header"><h5 class="mb-0">{{ __('production_execution.quality.decision_details') }}</h5></div>
                    <div class="card-body">
                        <dl class="row mb-0 quality-detail-list">
                            <dt class="col-sm-5">{{ __('production_execution.fields.notes') }}</dt><dd class="col-sm-7">{{ $record->notes ?: '—' }}</dd>
                            <dt class="col-sm-5">{{ __('production_execution.fields.defect_code') }}</dt><dd class="col-sm-7">{{ $record->defect_code ?: '—' }}</dd>
                            <dt class="col-sm-5">{{ __('production_execution.fields.corrective_action') }}</dt><dd class="col-sm-7">{{ $record->corrective_action ?: '—' }}</dd>
                            <dt class="col-sm-5">{{ __('production_execution.fields.rework_notes') }}</dt><dd class="col-sm-7">{{ $record->rework_notes ?: '—' }}</dd>
                            <dt class="col-sm-5">{{ __('production_execution.fields.reviewed_at') }}</dt><dd class="col-sm-7">{{ $record->reviewed_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                            <dt class="col-sm-5">{{ __('production_execution.fields.closed_at') }}</dt><dd class="col-sm-7">{{ $record->closed_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                            @if($record->rejection_reason)<dt class="col-sm-5 text-danger">{{ __('production_execution.fields.reason') }}</dt><dd class="col-sm-7 text-danger">{{ $record->rejection_reason }}</dd>@endif
                            @if($record->close_notes)<dt class="col-sm-5">{{ __('production_execution.fields.close_notes') }}</dt><dd class="col-sm-7">{{ $record->close_notes }}</dd>@endif
                        </dl>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between"><h5 class="mb-0">{{ __('production_execution.quality.evidence_gallery') }}</h5><span class="badge badge-subtle-primary">{{ $evidence->count() }}</span></div>
                    <div class="card-body">
                        @if ($evidence->isEmpty())
                            <div class="text-600">{{ __('production_execution.quality.no_evidence') }}</div>
                        @else
                            <div class="quality-evidence-gallery">
                                @foreach ($evidence as $file)
                                    <a class="quality-evidence-card" href="{{ $file['url'] }}" target="_blank" rel="noopener">
                                        @if ($file['is_image'])<img src="{{ $file['url'] }}" alt="{{ $file['name'] }}" loading="lazy">@else<span class="fas fa-file-pdf fa-3x text-danger" aria-hidden="true"></span>@endif
                                        <span class="fw-semibold text-break">{{ $file['name'] }}</span>
                                        @if($file['captured_at'])<small class="text-600">{{ $file['captured_at'] }}</small>@endif
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ __('production_execution.quality.inspection_chain') }}</h5></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>{{ __('production_execution.fields.document') }}</th><th>{{ __('production_execution.fields.reinspection') }}</th><th>{{ __('production_execution.fields.status') }}</th><th>{{ __('production_execution.fields.result') }}</th><th>{{ __('production_execution.fields.requested_at') }}</th></tr></thead>
                            <tbody>
                                @foreach($inspectionChain as $chainInspection)
                                    <tr class="{{ $chainInspection->is($record) ? 'table-primary' : '' }}">
                                        <td><a href="{{ route('admin.production.quality.show', $chainInspection->getKey()) }}">{{ $chainInspection->doc_num }}</a></td>
                                        <td>{{ $chainInspection->reinspection_number }}</td>
                                        <td>{{ __('production_execution.statuses.'.$chainInspection->status) }}</td>
                                        <td>{{ __('production_execution.quality_results.'.$chainInspection->result) }}</td>
                                        <td>{{ $chainInspection->requested_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
