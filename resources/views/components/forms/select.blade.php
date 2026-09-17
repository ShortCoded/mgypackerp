@props([
    'allowClear' => true,
    'id' => null,
    'multiple' => false,
    'name' => null,
    'placeholder' => null,
    'required' => false,
    'url' => null,
    'variant' => 'plain',
])

@php
    $variantClass = match ($variant) {
        'ajax' => 'js-select2-ajax',
        'local' => 'js-select2-local',
        default => null,
    };
    $providedClass = (string) $attributes->get('class', '');
    $providedClasses = preg_split('/\s+/', trim($providedClass), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $controlClasses = [];

    if (! in_array('form-select', $providedClasses, true)) {
        $controlClasses[] = 'form-select';
    }

    if ($variantClass !== null && ! in_array($variantClass, $providedClasses, true)) {
        $controlClasses[] = $variantClass;
    }

    $mergedAttributes = $attributes->class($controlClasses)->merge([
        'id' => $id,
        'name' => $name,
        'data-placeholder' => $placeholder,
        'data-allow-clear' => $variant !== 'plain' ? ($allowClear ? 'true' : 'false') : null,
        'data-url' => $url,
        'multiple' => $multiple,
        'required' => $required,
    ]);
    $attributeValues = $mergedAttributes->getAttributes();
    $orderedAttributes = [];

    foreach (['class', 'id', 'name'] as $attributeName) {
        if (array_key_exists($attributeName, $attributeValues)) {
            $orderedAttributes[$attributeName] = $attributeValues[$attributeName];
            unset($attributeValues[$attributeName]);
        }
    }

    $controlAttributes = new \Illuminate\View\ComponentAttributeBag([...$orderedAttributes, ...$attributeValues]);
@endphp

<select {{ $controlAttributes }}>{{ $slot }}</select>
