<?php

return [
    'record_in_use_translation_keys' => [
        'accounts.messages.delete_blocked_children',
        'archive.file_delete_blocked_used',
        'common.messages.related_data_delete_blocked',
        'companies.messages.related_data_exists',
        'cost_centers.messages.delete_blocked_children',
        'customers.messages.delete_blocked_transactions',
        'fixed_assets.messages.delete_blocked_lifecycle',
        'fixed_assets.messages.delete_blocked_transactions',
        'project_structures.messages.delete_blocked_children',
        'production_identifiers.messages.delete_blocked_children',
        'suppliers.messages.delete_blocked_transactions',
        'users.messages.related_data_exists',
    ],

    'constraints' => [
        'accounts_company_account_code_unique_active' => [
            'error_code' => 'duplicate_account_code',
            'translation_key' => 'erp_errors.duplicate_account_code',
            'field' => 'account_code',
            'status' => 422,
        ],
        'branch_halls_branch_id_name_unique' => [
            'error_code' => 'duplicate_name',
            'translation_key' => 'erp_errors.duplicate_name',
            'field' => 'name',
            'status' => 422,
        ],
        'production_identifier_types_company_name_unique_active' => [
            'error_code' => 'duplicate_name',
            'translation_key' => 'erp_errors.duplicate_name',
            'field' => 'name',
            'status' => 422,
        ],
        'customer_credit_allocations_idempotency_unique' => [
            'error_code' => 'duplicate_operation',
            'translation_key' => 'erp_errors.duplicate_operation',
            'field' => null,
            'status' => 409,
        ],
        'customer_credit_refunds_idempotency_unique' => [
            'error_code' => 'duplicate_operation',
            'translation_key' => 'erp_errors.duplicate_operation',
            'field' => null,
            'status' => 409,
        ],
    ],
    'constraint_patterns' => [
        '/_(name)_unique(?:_active)?$/D' => [
            'error_code' => 'duplicate_name',
            'translation_key' => 'erp_errors.duplicate_name',
            'field' => 'name',
            'status' => 422,
        ],
        '/_(account_code|code)_unique(?:_active)?$/D' => [
            'error_code' => 'duplicate_value',
            'translation_key' => 'erp_errors.duplicate_value',
            'field' => 'code',
            'status' => 422,
        ],
        '/_(doc_num|doc_number)_unique(?:_active)?$/D' => [
            'error_code' => 'duplicate_document_number',
            'translation_key' => 'erp_errors.duplicate_document_number',
            'field' => 'doc_number',
            'status' => 422,
        ],
    ],
];
