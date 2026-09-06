@props([
    'title',
    'tableId',
    'dataUrl' => null,
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
            <div class="erp-datatable-scroll" role="region" aria-label="{{ $title }}" tabindex="0">
                <table class="table table-sm table-hover mb-0 align-middle w-100 @if($dataUrl) data-table erp-datatable @endif" id="{{ $tableId }}" @if($dataUrl) data-url="{{ $dataUrl }}" @endif>
                    {{ $slot }}
                </table>
            </div>
        </div>
    </div>
</div>
