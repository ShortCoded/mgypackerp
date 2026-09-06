@php($cycle = app(\Modules\Purchases\Services\Reports\ProcurementCycleReport::class)->documentChain($record))
@if($cycle->isNotEmpty())
<div class="card mb-3">
    <div class="card-header py-2"><h6 class="mb-0">{{ __('Document cycle') }}</h6></div>
    <div class="card-body d-flex flex-wrap gap-2">
        @foreach($cycle as $node)
            @can($node['permission'])
                <a class="border rounded p-2 text-decoration-none" href="{{ $node['url'] }}">
                    <div class="small text-600">{{ $node['label'] }}</div>
                    <strong dir="ltr">{{ $node['doc_num'] }}</strong>
                    <div class="small">{{ __('procurement.statuses.'.($node['status'] ?? 'draft')) }}</div>
                    @if(isset($node['quantity']))<div class="small">{{ __('Quantity') }}: {{ app(\Modules\Core\Services\NumericFormatService::class)->format($node['quantity']) }}</div>@endif
                    @if(isset($node['amount']) && auth()->user()?->can('purchases.prices.view'))<div class="small">{{ __('Amount') }}: {{ app(\Modules\Core\Services\NumericFormatService::class)->format($node['amount']) }}</div>@endif
                    <div class="small text-600">{{ $node['date'] }} {{ $node['user'] }}</div>
                </a>
            @endcan
        @endforeach
    </div>
</div>
@endif

@include('modules.purchases.procurement.line-progress')
