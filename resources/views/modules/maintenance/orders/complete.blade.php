@extends('layouts.app')

@section('title', __('maintenance.orders.complete'))

@section('content')
    @php
        $laborDetails = old('labor_details', []);
        $materialLines = $order->materialRequests
            ->flatMap->lines
            ->filter(fn ($line) => bccomp(bcsub((string) $line->issued_quantity, (string) $line->returned_quantity, 8), '0', 8) > 0)
            ->values();
    @endphp

    <div class="production-mobile-workflow">
        @if ($errors->any())
            <div class="alert alert-danger"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <form method="POST" action="{{ route('admin.maintenance.orders.complete', $order) }}" class="card">
            @csrf
            <div class="card-header">
                <h5 class="mb-0">{{ $order->doc_num }} — {{ $order->asset?->asset_name ?? $order->mold?->name }}</h5>
                <p class="small text-600 mb-0">{{ __('maintenance.messages.technical_completion_help') }}</p>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">{{ __('maintenance.fields.diagnosis') }}</label><x-forms.textarea name="diagnosis" rows="4" required>{{ old('diagnosis', $order->diagnosis) }}</x-forms.textarea></div>
                    <div class="col-md-6"><label class="form-label">{{ __('maintenance.fields.root_cause') }}</label><x-forms.textarea name="root_cause" rows="4">{{ old('root_cause', $order->root_cause) }}</x-forms.textarea></div>
                    <div class="col-12"><label class="form-label">{{ __('maintenance.fields.work_performed') }}</label><x-forms.textarea name="work_performed" rows="4" required>{{ old('work_performed', $order->work_performed) }}</x-forms.textarea></div>

                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.test_result') }}</label><x-forms.select name="test_result" required>@foreach(['passed', 'failed'] as $result)<option value="{{ $result }}" @selected(old('test_result', $order->test_result ?? 'passed') === $result)>{{ __('maintenance.test_results.'.$result) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.repair_outcome') }}</label><x-forms.select name="repair_outcome"><option value="">—</option>@foreach(['permanent', 'temporary'] as $outcome)<option value="{{ $outcome }}" @selected(old('repair_outcome', $order->repair_outcome) === $outcome)>{{ __('maintenance.repair_outcomes.'.$outcome) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.machine_released_at') }}</label><x-forms.date-input name="machine_released_at" enable-time :value="old('machine_released_at', now()->format('Y-m-d H:i'))" /></div>
                    <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.follow_up_due_at') }}</label><x-forms.date-input name="follow_up_due_at" enable-time :value="old('follow_up_due_at', $order->follow_up_due_at?->format('Y-m-d H:i'))" /></div>

                    @if($materialLines->isNotEmpty())
                        <div class="col-12">
                            <div class="card border">
                                <div class="card-header"><h6 class="mb-0">{{ __('maintenance.fields.actual_material_usage') }}</h6><p class="small text-600 mb-0">{{ __('maintenance.messages.material_usage_help') }}</p></div>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead><tr><th>{{ __('maintenance.fields.item') }}</th><th>{{ __('maintenance.fields.issued_quantity') }}</th><th>{{ __('maintenance.fields.returned_quantity') }}</th><th>{{ __('maintenance.fields.consumed_quantity') }}</th></tr></thead>
                                        <tbody>
                                            @foreach($materialLines as $index => $line)
                                                @php($maximum = bcsub((string) $line->issued_quantity, (string) $line->returned_quantity, 8))
                                                <tr>
                                                    <td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}<x-forms.input type="hidden" name="material_usage[{{ $index }}][line_id]" :value="$line->getKey()" /></td>
                                                    <td dir="ltr">{{ $line->issued_quantity }}</td>
                                                    <td dir="ltr">{{ $line->returned_quantity }}</td>
                                                    <td><x-forms.input name="material_usage[{{ $index }}][consumed_quantity]" type="number" min="0" :max="$maximum" step="0.00000001" :value="old('material_usage.'.$index.'.consumed_quantity', $line->consumed_quantity)" required /></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="col-12">
                        <div class="card border">
                            <div class="card-header d-flex align-items-center justify-content-between gap-2"><div><h6 class="mb-0">{{ __('maintenance.fields.labor_details') }}</h6><p class="small text-600 mb-0">{{ __('maintenance.messages.labor_details_help') }}</p></div><button class="btn btn-sm btn-falcon-default" type="button" data-add-maintenance-labor>{{ __('maintenance.actions.add_participant') }}</button></div>
                            <div class="card-body" data-maintenance-labor-list>
                                @foreach($laborDetails as $index => $labor)
                                    <div class="row g-2 align-items-end mb-2" data-maintenance-labor-row>
                                        <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.participant_name') }}</label><x-forms.input name="labor_details[{{ $index }}][name]" data-name="name" :value="$labor['name'] ?? ''" required /></div>
                                        <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.discipline') }}</label><x-forms.select name="labor_details[{{ $index }}][discipline]" data-name="discipline"><option value="">—</option>@foreach(['electrical', 'mechanical', 'molds', 'other'] as $discipline)<option value="{{ $discipline }}" @selected(($labor['discipline'] ?? null) === $discipline)>{{ __('maintenance.disciplines.'.$discipline) }}</option>@endforeach</x-forms.select></div>
                                        <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.actual_hours') }}</label><x-forms.input name="labor_details[{{ $index }}][actual_hours]" data-name="actual_hours" type="number" min="0.01" step="0.01" :value="$labor['actual_hours'] ?? ''" required /></div>
                                        <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.notes') }}</label><x-forms.input name="labor_details[{{ $index }}][notes]" data-name="notes" :value="$labor['notes'] ?? ''" /></div>
                                        <div class="col-md-1"><button class="btn btn-outline-danger w-100" type="button" data-remove-maintenance-labor aria-label="{{ __('common.actions.delete') }}">&times;</button></div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="col-md-8"><label class="form-label">{{ __('maintenance.fields.completion_notes') }}</label><x-forms.textarea name="completion_notes" rows="2">{{ old('completion_notes', $order->completion_notes) }}</x-forms.textarea></div>
                    <div class="col-md-4"><label class="form-label">{{ __('maintenance.fields.next_due_date') }}</label><x-forms.date-input name="next_due_date" :value="old('next_due_date', $order->next_due_date?->toDateString())" /></div>
                </div>
            </div>
            <div class="card-footer text-end"><button class="btn btn-success">{{ __('maintenance.actions.record_test_result') }}</button></div>
        </form>
    </div>

    <template id="maintenance-labor-template">
        <div class="row g-2 align-items-end mb-2" data-maintenance-labor-row>
            <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.participant_name') }}</label><x-forms.input data-name="name" required /></div>
            <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.discipline') }}</label><x-forms.select data-name="discipline"><option value="">—</option>@foreach(['electrical', 'mechanical', 'molds', 'other'] as $discipline)<option value="{{ $discipline }}">{{ __('maintenance.disciplines.'.$discipline) }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-2"><label class="form-label">{{ __('maintenance.fields.actual_hours') }}</label><x-forms.input data-name="actual_hours" type="number" min="0.01" step="0.01" required /></div>
            <div class="col-md-3"><label class="form-label">{{ __('maintenance.fields.notes') }}</label><x-forms.input data-name="notes" /></div>
            <div class="col-md-1"><button class="btn btn-outline-danger w-100" type="button" data-remove-maintenance-labor aria-label="{{ __('common.actions.delete') }}">&times;</button></div>
        </div>
    </template>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const list = document.querySelector('[data-maintenance-labor-list]');
            const template = document.getElementById('maintenance-labor-template');
            const addButton = document.querySelector('[data-add-maintenance-labor]');
            if (! list || ! template || ! addButton) return;

            const renumber = () => list.querySelectorAll('[data-maintenance-labor-row]').forEach((row, index) => {
                row.querySelectorAll('[data-name]').forEach((input) => input.name = `labor_details[${index}][${input.dataset.name}]`);
            });
            addButton.addEventListener('click', () => {
                list.appendChild(template.content.cloneNode(true));
                renumber();
            });
            list.addEventListener('click', (event) => {
                const button = event.target.closest('[data-remove-maintenance-labor]');
                if (! button) return;
                button.closest('[data-maintenance-labor-row]')?.remove();
                renumber();
            });
        });
    </script>
@endpush
