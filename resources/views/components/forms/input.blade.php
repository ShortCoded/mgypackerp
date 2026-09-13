@props([
    'id' => null,
    'name' => null,
    'type' => 'text',
    'value' => null,
])

@php
    $unstyledTypes = ['checkbox', 'hidden', 'radio'];
    $providedClass = (string) $attributes->get('class', '');
    $needsControlClass = ! in_array($type, $unstyledTypes, true)
        && ! str_contains($providedClass, 'form-control');
    $defaultAttributes = in_array($type, $unstyledTypes, true)
        ? ['type' => $type, 'name' => $name, 'value' => $value, 'id' => $id]
        : ['id' => $id, 'name' => $name, 'type' => $type, 'value' => $value];
    $mergedAttributes = ($needsControlClass || $providedClass !== ''
        ? $attributes->class(['form-control' => $needsControlClass])
        : $attributes)->merge($defaultAttributes);
    $attributeOrder = in_array($type, $unstyledTypes, true)
        ? ['type', 'id', 'name', 'value', 'class']
        : ['class', 'id', 'name', 'type', 'value'];
    $attributeValues = $mergedAttributes->getAttributes();
    $orderedAttributes = [];

    foreach ($attributeOrder as $attributeName) {
        if (array_key_exists($attributeName, $attributeValues)) {
            $orderedAttributes[$attributeName] = $attributeValues[$attributeName];
            unset($attributeValues[$attributeName]);
        }
    }

    $controlAttributes = new \Illuminate\View\ComponentAttributeBag([...$orderedAttributes, ...$attributeValues]);
@endphp

<input {{ $controlAttributes }}>
