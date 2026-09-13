@props([
    'id' => null,
    'name' => null,
    'rows' => 3,
])

@php
    $providedClass = (string) $attributes->get('class', '');
    $mergedAttributes = $attributes
        ->class(['form-control' => ! str_contains($providedClass, 'form-control')])
        ->merge([
            'id' => $id,
            'name' => $name,
            'rows' => $rows,
        ]);
    $attributeValues = $mergedAttributes->getAttributes();
    $orderedAttributes = [];

    foreach (['class', 'id', 'name', 'rows'] as $attributeName) {
        if (array_key_exists($attributeName, $attributeValues)) {
            $orderedAttributes[$attributeName] = $attributeValues[$attributeName];
            unset($attributeValues[$attributeName]);
        }
    }

    $controlAttributes = new \Illuminate\View\ComponentAttributeBag([...$orderedAttributes, ...$attributeValues]);
@endphp

<textarea {{ $controlAttributes }}>{{ $slot }}</textarea>
