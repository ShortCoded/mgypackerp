<?php

return [
    'types' => [
        'expense_analysis' => [
            'title' => 'Expense Analysis',
            'description' => 'Posted operating expenses by account, classification, cost center, branch, source document, and currency.',
        ],
        'financial_ratios' => [
            'title' => 'Financial Ratios',
            'description' => 'Ratios calculated from the canonical financial-statement and posted-journal sources.',
        ],
    ],
    'filters_title' => 'Analysis filters',
    'filters' => [
        'from_date' => 'From date', 'to_date' => 'To date',
        'comparison_from_date' => 'Comparison from', 'comparison_to_date' => 'Comparison to',
        'view_mode' => 'Presentation', 'account_doc_num' => 'Expense account',
        'classification_code' => 'Expense classification', 'cost_center_doc_num' => 'Cost center',
        'branch_doc_num' => 'Branch', 'source_type' => 'Source type',
        'source_doc_num' => 'Source document', 'currency_doc_num' => 'Original currency',
    ],
    'columns' => [
        'date' => 'Date', 'journal' => 'Journal entry', 'source_document' => 'Source document',
        'account' => 'Expense account', 'classification' => 'Classification', 'cost_center' => 'Cost center',
        'branch' => 'Branch', 'currency' => 'Original currency', 'debit' => 'Debit', 'credit' => 'Credit',
        'amount_base' => 'Expense in base currency', 'source_type' => 'Source type', 'ratio' => 'Ratio',
        'value' => 'Current value', 'comparison' => 'Comparison value', 'formula' => 'Formula',
        'numerator' => 'Numerator', 'denominator' => 'Denominator', 'status' => 'Calculation status',
    ],
    'ratios' => [
        'current_ratio' => 'Current Ratio', 'gross_profit_margin' => 'Gross Profit Margin',
        'net_profit_margin' => 'Net Profit Margin', 'inventory_turnover' => 'Inventory Turnover',
    ],
    'formulas' => [
        'current_ratio' => 'Current assets / Current liabilities',
        'gross_profit_margin' => 'Gross profit / Net revenue × 100',
        'net_profit_margin' => 'Net profit / Net revenue × 100',
        'inventory_turnover' => 'Cost of sales / Average inventory',
    ],
    'values' => [
        'summary' => 'Summary', 'detail' => 'Detail', 'manual' => 'Manual journal',
        'calculable' => 'Calculated', 'not_calculable' => 'Not calculable', 'unspecified' => 'Unspecified',
    ],
    'notices' => [
        'expense_posted_base_currency' => 'Only posted journals are included. Period-closing transfers are excluded, and totals use base currency so original currencies are not combined.',
        'ratios_no_benchmark' => 'No good/bad judgment is applied. A ratio is marked not calculable when a mapped component or denominator is unavailable.',
    ],
    'comparison_total' => 'Comparison-period total in base currency',
    'no_results' => 'No matching posted accounting data was found.',
];
