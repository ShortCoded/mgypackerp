@props([
    'metadata' => [],
    'showDeleted' => false,
    'showRestored' => false,
])

@php
    $fields = [
        ['key' => 'created_by', 'label' => __('common.fields.created_by'), 'date' => false],
        ['key' => 'created_at', 'label' => __('common.fields.created_at'), 'date' => true],
        ['key' => 'updated_by', 'label' => __('common.fields.updated_by'), 'date' => false],
        ['key' => 'updated_at', 'label' => __('common.fields.updated_at'), 'date' => true],
    ];

    if ($showDeleted) {
        $fields[] = ['key' => 'deleted_by', 'label' => __('common.fields.deleted_by'), 'date' => false];
        $fields[] = ['key' => 'deleted_at', 'label' => __('common.fields.deleted_at'), 'date' => true];
    } elseif ($showRestored) {
        $fields[] = ['key' => 'restored_by', 'label' => __('common.fields.restored_by'), 'date' => false];
        $fields[] = ['key' => 'restored_at', 'label' => __('common.fields.restored_at'), 'date' => true];
    }

    $columnClass = ($showDeleted || $showRestored) ? 'col-md-6 col-lg-4 col-xl-2' : 'col-md-6 col-xl-3';
@endphp

<div {{ $attributes->merge(['class' => 'mt-1 row g-3', 'role' => 'group', 'aria-label' => __('common.sections.audit_information')]) }}>
    @foreach ($fields as $field)
        <x-forms.view-field
            :label="$field['label']"
            :value="$metadata[$field['key']] ?? null"
            :dir="$field['date'] ? 'ltr' : null"
            :input-class="$field['date'] ? 'date-value' : ''"
            :class="$columnClass"
        />
    @endforeach
</div>
