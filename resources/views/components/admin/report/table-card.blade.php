@props([
    'title',
    'tableId',
    'dataUrl',
])

<div {{ $attributes->class(['card erp-datatable-card report-table-card']) }}>
    <div class="card-header py-2 border-bottom border-200">
        <div class="row flex-between-center g-2">
            <div class="col-auto">
                <h6 class="mb-0">{{ $title }}</h6>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="falcon-data-table">
            <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle w-100" id="{{ $tableId }}" data-url="{{ $dataUrl }}" style="width: 100%;">
                {{ $slot }}
            </table>
        </div>
    </div>
</div>
