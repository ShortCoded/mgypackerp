<?php

return [
    'title' => 'Price Lists', 'create' => 'Create Price List', 'automatic_code' => 'Generated automatically',
    'help' => 'Define general or customer-specific sales prices and the allowed discount limits.',
    'form_help' => 'Prices use the product base unit and are converted automatically for equivalent units in sales documents.',
    'search' => 'Search by code or customer', 'empty' => 'No price lists found.',
    'customer_source' => 'Customer price list', 'general_source' => 'General price list',
    'not_selected' => '—',
    'general' => 'General price list', 'open_ended' => 'Open-ended', 'customer_help' => 'Leave customer empty to make this a general list.',
    'valid_until_help' => 'Optional; leave empty for an open-ended list.', 'base_unit_help' => 'Prices and fixed discount limits are per base unit.',
    'add_item' => 'Add item', 'no_discount' => 'No discount', 'percentage' => 'Percentage', 'fixed' => 'Fixed value',
    'select_all' => 'Select all visible price lists', 'bulk_action' => 'Bulk action for price lists',
    'fields' => [
        'code' => 'Code', 'date' => 'Date', 'customer' => 'Customer (optional)', 'currency' => 'Currency',
        'valid_from' => 'Start date', 'valid_until' => 'End date', 'validity' => 'Validity', 'scope' => 'Scope',
        'items' => 'Products', 'product' => 'Product', 'price' => 'Price', 'discount_type' => 'Allowed discount type',
        'discount_value' => 'Discount limit', 'notes' => 'Notes',
    ],
    'validation' => [
        'customer' => 'The customer is not valid for the operating company.', 'currency' => 'The currency is not valid for the operating company.',
        'product' => 'The product is not sales eligible for the operating company.', 'percentage' => 'The allowed discount percentage cannot exceed 100%.',
    ],
    'messages' => [
        'created' => 'Price list created.', 'updated' => 'Price list updated.', 'deleted' => 'Price list deleted.',
        'restored' => 'Price list restored.', 'restore_not_allowed' => 'The price list is not deleted.',
        'bulk_deleted' => ':count price lists deleted.',
        'delete_confirm_title' => 'Delete price list?', 'delete_confirm_text' => 'The list will move to trash and can be restored later.', 'delete_confirm_yes' => 'Yes, delete',
        'bulk_delete_confirm_title' => 'Delete selected price lists?', 'bulk_delete_confirm_text' => ':count price lists will move to trash.', 'bulk_delete_confirm_yes' => 'Yes, delete selected',
        'restore_confirm_title' => 'Restore price list?', 'restore_confirm_text' => 'The list will return to active price lists.', 'restore_confirm_yes' => 'Yes, restore',
        'unpriced_products' => 'The document was not saved because these items have no price: :products',
        'discount_exceeded' => 'The discount for :product exceeds the allowed maximum (:maximum).',
    ],
];
