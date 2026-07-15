<?php

return [
    'title' => 'Units',
    'singular' => 'Unit',
    'create' => 'Create Unit',
    'edit' => 'Edit Unit',
    'view' => 'View Unit',
    'fields' => [
        'equivalent_value' => 'Equivalent Value',
        'equivalent_unit' => 'Equivalent Unit',
        'equivalent_to' => 'Equivalent To',
    ],
    'equivalence_text' => '1 :unit = :value :equivalent_unit',
    'validation' => [
        'equivalent_value_required' => 'Equivalent value is required when an equivalent unit is selected.',
        'equivalent_value_numeric' => 'Equivalent value must be a number.',
        'equivalent_value_gt_zero' => 'Equivalent value must be greater than 0.',
        'equivalent_unit_required' => 'Equivalent unit is required when an equivalent value is filled.',
        'equivalent_unit_exists' => 'Selected equivalent unit is not available.',
        'equivalent_unit_self' => 'A unit cannot reference itself as its equivalent unit.',
    ],
];
