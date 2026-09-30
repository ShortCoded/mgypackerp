<?php

return [
    'title' => 'Open Document',
    'fields' => [
        'document_type' => 'Document',
        'from_number' => 'From Number',
        'to_number' => 'To Number',
        'reason' => 'Reason for reopening approved documents',
    ],
    'actions' => [
        'open' => 'Open',
    ],
    'documents' => [
        'opening_balances' => 'Opening Balances',
        'opening_stocks' => 'Opening Stock',
        'opening_stock_pricings' => 'Opening Stock Pricing',
        'sales_orders' => 'Sales Orders',
        'sales_requests' => 'Sales Requests',
        'customer_invoices' => 'Sales Invoices',
        'purchase_orders' => 'Purchase Orders',
        'purchase_requisitions' => 'Purchase Requisitions',
        'production_material_requests' => 'Production Material Requests',
    ],
    'messages' => [
        'confirm' => 'Do you want to reopen the selected documents?',
        'opened' => ':count documents were reopened successfully.',
        'none_reopenable' => 'There are no documents that can be reopened.',
        'skipped_approved' => ':count approved documents were skipped.',
        'skipped_already_open' => ':count already-open documents were skipped.',
        'skipped_deleted' => ':count deleted documents were skipped.',
        'skipped_blocked' => ':count documents with downstream activity or unsafe state were skipped.',
        'not_found' => ':count document numbers were not found in the current period.',
    ],
    'validation' => [
        'from_lte_to' => 'From number must be less than or equal to To number.',
        'invalid_document_type' => 'The selected document type is not supported.',
        'reason_required' => 'A reopening reason is required.',
        'range_too_large' => 'You can reopen at most :count documents at a time.',
        'reopen_context_or_reason' => 'Select the document branch and provide a reopening reason.',
    ],
];
