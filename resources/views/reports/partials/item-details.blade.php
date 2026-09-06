@php
    $snapshot = $line->product_snapshot ?? $line->item_snapshot ?? [];
    $product = $product ?? $line->product;
    $itemCode = $snapshot['doc_num'] ?? $line->product_code_snapshot ?? $product?->doc_num;
    $itemName = $snapshot['name'] ?? $line->product_name_snapshot ?? $product?->name ?? $line->description;
    $details = array_filter([
        __('Classification') => $product?->item_classification ? __('products.classifications.'.$product->item_classification) : ($snapshot['item_classification'] ?? null),
        __('Material') => $snapshot['raw_material'] ?? data_get($line->specifications, 'material') ?? data_get($line->specifications, 'raw_material') ?? $product?->raw_material_name,
        __('Color') => $snapshot['color'] ?? data_get($line->specifications, 'color') ?? $product?->color?->name,
        __('Packing') => $snapshot['packing'] ?? data_get($line->specifications, 'packaging') ?? $line->packing ?? $line->packing_quantity,
    ], fn ($value) => filled($value));
@endphp
@if(($showItemCode ?? true) && $itemCode)<div class="document-item-code" dir="ltr">{{ $itemCode }}</div>@endif
<strong>{{ $itemName }}</strong>
@if(filled($line->description) && $line->description !== $itemName)<div>{{ $line->description }}</div>@endif
@if($details)<div class="document-item-details">@foreach($details as $label => $value){{ $label }}: {{ $value }}@unless($loop->last) · @endunless @endforeach</div>@endif
