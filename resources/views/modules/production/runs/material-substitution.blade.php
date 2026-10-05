@extends('layouts.app')
@section('title', __('production_material_substitution.title'))
@section('content')
@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))
@php($dates = app(\Modules\Core\Services\DateFormatService::class))
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between"><h5>{{ __('production_material_substitution.title') }} — {{ $record->run_number }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.runs.show', $record) }}">{{ __('Back') }}</a></div>
    <div class="card-body">
        <p>{{ __('production_material_substitution.help') }}</p>
        @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
        @if($blocker !== null)<div class="alert alert-warning">{{ $blocker }}</div>@endif
        <p>{{ __('production_material_substitution.cost_current') }}: {{ $numbers->format($impact['direct_material_cost']) }}</p>
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>{{ __('production_material_substitution.original') }}</th><th>{{ __('Unit') }}</th><th>{{ __('Planned') }}</th><th>{{ __('Issued') }}</th><th>{{ __('Returned') }}</th><th>{{ __('Actions') }}</th></tr></thead><tbody>
        @foreach($record->requirements as $line)
        <tr><td>{{ $line->product?->doc_num }} — {{ $line->product?->name }}</td><td>{{ $line->unit?->name }}</td><td>{{ $numbers->format($line->planned_quantity) }}</td><td>{{ $numbers->format($line->issued_quantity) }}</td><td>{{ $numbers->format($line->returned_quantity) }}</td>
        <td><a class="btn btn-sm btn-falcon-default" href="{{ route('admin.production.runs.material-substitutions.index', [$record, 'requirement' => $line->public_id]) }}">{{ __('Select') }}</a></td></tr>
        @endforeach</tbody></table></div>
        @if($selected !== null && $blocker === null && auth()->user()->can('production.runs.correct') && auth()->user()->can('production.runs.issue'))
        <form method="post" action="{{ route('admin.production.runs.material-substitutions.store', $record) }}" novalidate>@csrf
            <x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
            <x-forms.input type="hidden" name="fingerprint" :value="$fingerprint" />
            <x-forms.input type="hidden" name="requirement_public_id" :value="$selected->public_id" />
            <h6>{{ __('production_material_substitution.original') }}: {{ $selected->product?->doc_num }} — {{ $selected->product?->name }}</h6>
            <div class="row g-3">
                <div class="col-md-6"><x-forms.label for="replacement_product_doc_num" :required="true" :label="__('production_material_substitution.replacement')" /><x-forms.select variant="ajax" id="replacement_product_doc_num" name="replacement_product_doc_num" :url="route('admin.production.runs.material-substitutions.products', [$record, 'requirement' => $selected->public_id])" :placeholder="__('production_material_substitution.select_replacement')" required /></div>
                <div class="col-md-3"><x-forms.label for="branch_store_id" :required="true" :label="__('Store')" /><x-forms.select variant="local" id="branch_store_id" name="branch_store_id" required>
                @foreach($stores as $store)<option value="{{ $store->id }}" @selected((string)old('branch_store_id') === (string)$store->id)>{{ $store->name }}</option>@endforeach</x-forms.select></div>
                <div class="col-md-3"><x-forms.label for="quantity" :required="true" :label="__('Quantity').' — '.$selected->unit?->name" /><x-forms.numeric-input id="quantity" name="quantity" :value="old('quantity')" /></div>
                <div class="col-12"><x-forms.label for="reason" :required="true" :label="__('production_material_substitution.reason')" /><x-forms.textarea id="reason" name="reason">{{ old('reason') }}</x-forms.textarea></div>
                <div class="col-12"><button class="btn btn-primary">{{ __('production_material_substitution.prepare') }}</button></div>
            </div>
        </form>
        @endif
    </div>
</div>
<div class="card"><div class="card-header">{{ __('production_material_substitution.history') }}</div><div class="card-body">
@foreach($proposals as $proposal)
@php($source = json_decode($proposal->source_snapshot, true, 512, JSON_THROW_ON_ERROR))
@php($target = json_decode($proposal->replacement_snapshot, true, 512, JSON_THROW_ON_ERROR))
@php($original = collect($source['requirements'])->firstWhere('id', $proposal->original_requirement_id))
@php($originalProduct = $record->requirements->firstWhere('id', $proposal->original_requirement_id)?->product)
<div class="border rounded p-3 mb-3">
    <h6>#{{ $proposal->id }} — {{ __('production_material_substitution.'.$proposal->status) }}</h6>
    <p>{{ $originalProduct?->doc_num }} — {{ $originalProduct?->name }} → {{ $target['product']['doc_num'] }} — {{ $target['product']['name'] }} — {{ $numbers->format($proposal->quantity) }} {{ $record->requirements->firstWhere('id', $proposal->original_requirement_id)?->unit?->name }}</p>
    <p>{{ $proposal->reason }} — {{ $dates->formatDateTime($proposal->created_at) }}</p>
    @if($proposal->execution_snapshot !== null)
        @php($execution = json_decode($proposal->execution_snapshot, true, 512, JSON_THROW_ON_ERROR))
        <p>{{ $proposal->recipe_approval_evidence }}</p>
        <p>{{ __('production_material_substitution.cost_before') }}: {{ $numbers->format($execution['before_cost']['direct_material_cost']) }} → {{ __('production_material_substitution.cost_after') }}: {{ $numbers->format($execution['after_cost']['direct_material_cost']) }}</p>
        <p>{{ __('production_material_substitution.documents') }}:
        @foreach(['return', 'issue'] as $kind)
        @can('inventory.documents.view')<a href="{{ route('admin.inventory.documents.show', $execution[$kind]['document']['doc_num']) }}">{{ $execution[$kind]['document']['doc_num'] }}</a>@else{{ $execution[$kind]['document']['doc_num'] }}@endcan
        @endforeach</p>
    @endif
    @if($proposal->status === 'prepared' && auth()->user()->can('production.runs.correct_approve'))
        @if((int)$proposal->prepared_by !== (int)auth()->id() && auth()->user()->can('products.edit') && auth()->user()->can('production.runs.issue'))
        <form method="post" action="{{ route('admin.production.runs.material-substitutions.approve', [$record, $proposal->id]) }}" novalidate>@csrf
            <x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
            <x-forms.label :for="'recipe-evidence-'.$proposal->id" :required="true" :label="__('production_material_substitution.evidence')" />
            <x-forms.textarea :id="'recipe-evidence-'.$proposal->id" name="recipe_approval_evidence">{{ old('recipe_approval_evidence') }}</x-forms.textarea>
            <div class="form-check my-3"><x-forms.input class="form-check-input" type="checkbox" name="recipe_approved" value="1" id="recipe-approved-{{ $proposal->id }}" required /><label class="form-check-label" for="recipe-approved-{{ $proposal->id }}">{{ __('production_material_substitution.confirm') }}</label></div>
            <button class="btn btn-success">{{ __('production_material_substitution.approve') }}</button>
        </form>
        @else<p class="text-muted">{{ __('production_material_substitution.'.((int)$proposal->prepared_by === (int)auth()->id() ? 'independent' : 'permissions')) }}</p>@endif
        <form method="post" class="mt-2" action="{{ route('admin.production.runs.material-substitutions.reject', [$record, $proposal->id]) }}">@csrf<button class="btn btn-falcon-default">{{ __('production_material_substitution.reject') }}</button></form>
    @endif
</div>
@endforeach
{{ $proposals->links() }}
</div></div>
@endsection
