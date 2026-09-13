<?php

return [
    'valuation_methods' => [
        'moving_average' => 'Perpetual moving weighted average (net quantity and net inventory value)',
    ],
    'errors' => [
        'non_inventory_product' => ':event cannot be posted for a service or unsupported product classification.',
        'main_currency_required' => 'Inventory accounting cannot be posted because the company main currency is not configured.',
        'zero_cost' => ':event cannot be posted because the canonical inventory value is zero.',
    ],
];
