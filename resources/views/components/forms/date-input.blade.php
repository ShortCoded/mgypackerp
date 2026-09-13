@props([
    'enableTime' => false,
    'id' => null,
    'minuteIncrement' => 5,
    'name' => null,
    'required' => false,
    'time24hr' => true,
    'type' => 'text',
    'value' => null,
])

@php
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $enableTime = $enableTime || in_array($attributes->get('data-enable-time'), [true, 1, '1', 'true'], true);
    $minuteIncrement = $attributes->get('data-minute-increment', $minuteIncrement);
    $time24hr = in_array($attributes->get('data-time-24hr', $time24hr), [true, 1, '1', 'true'], true);
    $controlAttributes = $attributes->except(['data-enable-time', 'data-minute-increment', 'data-time-24hr']);
    $providedClass = (string) $controlAttributes->get('class', '');
    $rawValue = trim((string) ($value ?? ''));
    $storageValue = $enableTime
        ? ($dates->normalizeDateTimeForStorage($rawValue) ?? $rawValue)
        : ($dates->normalizeForStorage($rawValue) ?? $rawValue);
    $pickerAttributes = [
        'autocomplete' => 'off',
        'data-date-format' => $enableTime ? $dates->jsDateTimeFormat() : $dates->jsDateFormat(),
        'data-locale' => app()->getLocale(),
        'data-storage-format' => $enableTime ? 'Y-m-d H:i:S' : 'Y-m-d',
        'dir' => 'ltr',
    ];

    if ($enableTime) {
        $pickerAttributes['data-enable-time'] = 'true';
        $pickerAttributes['data-minute-increment'] = $minuteIncrement;
        $pickerAttributes['data-time-24hr'] = $time24hr ? 'true' : 'false';
    }

    $mergedAttributes = $controlAttributes->class([
        'form-control' => ! str_contains($providedClass, 'form-control'),
        'js-date-picker' => ! str_contains($providedClass, 'js-date-picker'),
    ])->merge([
        'id' => $id,
        'name' => $name,
        'type' => 'text',
        'value' => $storageValue,
        'required' => $required,
        ...$pickerAttributes,
    ]);
    $attributeValues = $mergedAttributes->getAttributes();
    $orderedAttributes = [];

    foreach (['class', 'id', 'name', 'type', 'value'] as $attributeName) {
        if (array_key_exists($attributeName, $attributeValues)) {
            $orderedAttributes[$attributeName] = $attributeValues[$attributeName];
            unset($attributeValues[$attributeName]);
        }
    }

    $controlAttributes = new \Illuminate\View\ComponentAttributeBag([...$orderedAttributes, ...$attributeValues]);
@endphp

<input {{ $controlAttributes }}>
