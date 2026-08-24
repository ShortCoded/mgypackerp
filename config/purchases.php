<?php

return [
    'accounts' => [
        'freight_expense' => env('PURCHASE_FREIGHT_ACCOUNT_CODE', '526'),
        'recoverable_input_vat' => env('PURCHASE_INPUT_VAT_ACCOUNT_CODE', '2131'),
        'supplier_payment_papers' => env('SUPPLIER_PAYMENT_PAPER_ACCOUNT_CODE', '2112'),
    ],
];
