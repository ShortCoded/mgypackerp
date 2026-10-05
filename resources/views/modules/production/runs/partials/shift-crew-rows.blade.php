<div data-shift-crew-editor data-next-index="1">
    <div data-shift-crew-rows>@include('modules.production.runs.partials.shift-crew-row', ['index' => 0])</div>
    <template data-shift-crew-template>@include('modules.production.runs.partials.shift-crew-row', ['index' => '__INDEX__'])</template>
    <button class="btn btn-outline-primary btn-sm my-2" type="button" data-add-shift-worker>{{ __('production_execution.actions.add_worker') }}</button>
</div>
