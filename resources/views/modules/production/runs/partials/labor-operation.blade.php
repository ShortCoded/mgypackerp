            <form method="POST" novalidate action="{{ route('admin.production.runs.labor', $record) }}">@csrf<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
                <x-forms.line-item-cards :line-label="__('production_execution.labor.worker_line')" />
                <div class="card-body">
                    <div class="row g-3 mb-3"><div class="col-12 col-md-4"><label class="form-label">{{ __('production_execution.fields.actual_labor_count') }}</label><x-forms.numeric-input class="form-control" name="actual_labor_count" :scale="0" min="1" step="1" arrow-step="1" :value="$record->actual_labor_count ?? max($record->planned_labor_count ?? 0, $laborRows->count(), 1)" required /></div></div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>{{ __('production_execution.fields.worker_name') }}</th><th>{{ __('production_execution.fields.worker_role') }}</th><th>{{ __('production_execution.fields.planned_hours') }}</th><th>{{ __('production_execution.fields.actual_hours') }}</th><th>{{ __('production_execution.fields.daily_work_hours') }}</th><th>{{ __('production_execution.fields.piece_quantity') }}</th><th>{{ __('production_execution.fields.notes') }}</th><th></th></tr></thead>
                            <tbody data-labor-rows>
                                @forelse($laborRows as $index => $labor)
                                    <tr data-labor-row>
                                        <td><x-forms.select variant="ajax" class="form-select-sm" name="labor_details[{{ $index }}][employee_id]" :url="route('admin.production.runs.select2.workers', ['identifier' => 'id'])" required>@if(filled($labor['employee_id'] ?? null))<option value="{{ $labor['employee_id'] }}" selected>{{ $workers->get($labor['employee_id'])?->doc_num }} — {{ $labor['name'] ?? $workers->get($labor['employee_id'])?->full_name }}</option>@endif</x-forms.select></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[{{ $index }}][role]" :value="$labor['role'] ?? ''" /></td>
                                        <td><x-forms.numeric-input class="form-control-sm" name="labor_details[{{ $index }}][planned_hours]" :scale="2" min="0" step="0.25" arrow-step="1" :value="$labor['planned_hours'] ?? ''" /></td>
                                        <td><x-forms.numeric-input class="form-control-sm" name="labor_details[{{ $index }}][actual_hours]" :scale="2" min="0.01" step="0.25" arrow-step="1" :value="$labor['actual_hours'] ?? ''" required /></td>
                                        <td style="min-width:260px">@include('modules.production.runs.partials.labor-work-segments', ['index' => $index, 'labor' => $labor])</td>
                                        <td><x-forms.numeric-input class="form-control-sm" name="labor_details[{{ $index }}][piece_quantity]" :scale="8" min="0.00000001" step="1" arrow-step="1" :value="$labor['piece_quantity'] ?? ''" /></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[{{ $index }}][notes]" :value="$labor['notes'] ?? ''" /></td>
                                        <td><div class="d-flex gap-2"><button class="btn btn-sm btn-outline-secondary" type="button" data-duplicate-labor-row aria-label="{{ __('production_execution.actions.duplicate_line') }}"><span class="fas fa-copy"></span></button><button class="btn btn-sm btn-outline-danger" type="button" data-remove-labor-row aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-times"></span></button></div></td>
                                    </tr>
                                @empty
                                    <tr data-labor-row>
                                        <td><x-forms.select variant="ajax" class="form-select-sm" name="labor_details[0][employee_id]" :url="route('admin.production.runs.select2.workers', ['identifier' => 'id'])" required><option value="">—</option></x-forms.select></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[0][role]" /></td>
                                        <td><x-forms.numeric-input class="form-control-sm" name="labor_details[0][planned_hours]" :scale="2" min="0" step="0.25" arrow-step="1" /></td>
                                        <td><x-forms.numeric-input class="form-control-sm" name="labor_details[0][actual_hours]" :scale="2" min="0.01" step="0.25" arrow-step="1" required /></td>
                                        <td style="min-width:260px">@include('modules.production.runs.partials.labor-work-segments', ['index' => 0, 'labor' => []])</td>
                                        <td><x-forms.numeric-input class="form-control-sm" name="labor_details[0][piece_quantity]" :scale="8" min="0.00000001" step="1" arrow-step="1" /></td>
                                        <td><x-forms.input class="form-control form-control-sm" name="labor_details[0][notes]" /></td>
                                        <td><div class="d-flex gap-2"><button class="btn btn-sm btn-outline-secondary" type="button" data-duplicate-labor-row aria-label="{{ __('production_execution.actions.duplicate_line') }}"><span class="fas fa-copy"></span></button><button class="btn btn-sm btn-outline-danger" type="button" data-remove-labor-row aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-times"></span></button></div></td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <template data-labor-row-template>
                        <tr data-labor-row>
                            <td><x-forms.select variant="ajax" class="form-select-sm" name="labor_details[__INDEX__][employee_id]" :url="route('admin.production.runs.select2.workers', ['identifier' => 'id'])" required><option value="">—</option></x-forms.select></td>
                            <td><x-forms.input class="form-control-sm" name="labor_details[__INDEX__][role]" type="text" /></td>
                            <td><x-forms.numeric-input class="form-control-sm" name="labor_details[__INDEX__][planned_hours]" :scale="2" min="0" step="0.25" arrow-step="1" /></td>
                            <td><x-forms.numeric-input class="form-control-sm" name="labor_details[__INDEX__][actual_hours]" :scale="2" min="0.01" step="0.25" arrow-step="1" required /></td>
                            <td style="min-width:260px">@include('modules.production.runs.partials.labor-work-segments', ['index' => '__INDEX__', 'labor' => []])</td>
                            <td><x-forms.numeric-input class="form-control-sm" name="labor_details[__INDEX__][piece_quantity]" :scale="8" min="0.00000001" step="1" arrow-step="1" /></td>
                            <td><x-forms.input class="form-control-sm" name="labor_details[__INDEX__][notes]" type="text" /></td>
                            <td><div class="d-flex gap-2"><button class="btn btn-sm btn-outline-secondary" type="button" data-duplicate-labor-row aria-label="{{ __('production_execution.actions.duplicate_line') }}"><span class="fas fa-copy"></span></button><button class="btn btn-sm btn-outline-danger" type="button" data-remove-labor-row aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-times"></span></button></div></td>
                        </tr>
                    </template>
                </div>
                <div class="card-footer d-flex flex-wrap justify-content-between gap-2"><button class="btn btn-falcon-primary btn-sm" type="button" data-add-labor-row><span class="fas fa-plus me-1"></span>{{ __('production_execution.actions.add_worker') }}</button><button class="btn btn-primary">{{ __('production_execution.actions.save_labor') }}</button></div>
            </form>
