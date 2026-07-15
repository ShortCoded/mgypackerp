<?php

return [
    'title' => 'الوحدات',
    'singular' => 'الوحدة',
    'create' => 'إنشاء وحدة',
    'edit' => 'تعديل وحدة',
    'view' => 'عرض وحدة',
    'fields' => [
        'equivalent_value' => 'ما يعادل',
        'equivalent_unit' => 'الوحدة المقابلة',
        'equivalent_to' => 'ما يعادل',
    ],
    'equivalence_text' => '1 :unit = :value :equivalent_unit',
    'validation' => [
        'equivalent_value_required' => 'قيمة المعادل مطلوبة عند اختيار وحدة مقابلة.',
        'equivalent_value_numeric' => 'يجب أن تكون قيمة المعادل رقمًا.',
        'equivalent_value_gt_zero' => 'يجب أن تكون قيمة المعادل أكبر من 0.',
        'equivalent_unit_required' => 'الوحدة المقابلة مطلوبة عند إدخال قيمة المعادل.',
        'equivalent_unit_exists' => 'الوحدة المقابلة المحددة غير متاحة.',
        'equivalent_unit_self' => 'لا يمكن أن تشير الوحدة إلى نفسها كوحدة مقابلة.',
    ],
];
