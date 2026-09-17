@props([
    'id' => null,
    'name' => null,
    'type' => 'text',
    'value' => null,
])

@php
    $unstyledTypes = ['checkbox', 'hidden', 'radio'];
    $controlClass = $type === 'range' ? 'form-range' : 'form-control';
    $providedClass = (string) $attributes->get('class', '');
    $providedClasses = preg_split('/\s+/', trim($providedClass), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $needsControlClass = ! in_array($type, $unstyledTypes, true)
        && ! in_array($controlClass, $providedClasses, true);
    $defaultAttributes = in_array($type, $unstyledTypes, true)
        ? ['type' => $type, 'name' => $name, 'value' => $value, 'id' => $id]
        : ['id' => $id, 'name' => $name, 'type' => $type, 'value' => $value];
    $mergedAttributes = ($needsControlClass || $providedClass !== ''
        ? $attributes->class($needsControlClass ? [$controlClass] : [])
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
