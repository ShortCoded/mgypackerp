<?php

return [
    'title' => 'Cashbox Count',
    'description' => 'Compare a physical cash count with the approved book balance for each cashbox and currency.',
    'worksheet_title' => 'Count worksheet',
    'worksheet_notice' => 'The book balance is calculated from approved cash movements as of the selected date and is saved as an immutable snapshot. Saving a variance does not post an accounting adjustment.',
    'history_title' => 'Saved cashbox counts',
    'document_title' => 'Cashbox count :document',
    'edit_title' => 'Update reopened count',
    'filters_title' => 'Count scope',
    'results_count' => ':count balances',
    'no_results' => 'No approved cash balance exists in the selected scope.',
    'no_history' => 'No saved cashbox count exists in the selected branch.',
    'filters' => [
        'as_of_date' => 'As of date',
        'cashbox' => 'Cashbox',
        'currency' => 'Currency',
    ],
    'columns' => [
        'cashbox' => 'Cashbox',
        'branch' => 'Branch',
        'currency' => 'Currency',
        'book_balance' => 'Book balance',
        'counted_balance' => 'Physical count',
        'variance' => 'Variance',
        'document' => 'Document',
        'count_date' => 'Count date',
        'status' => 'Status',
        'notes' => 'Notes',
        'creator' => 'Created by',
    ],
    'actions' => [
        'print_worksheet' => 'Print worksheet',
        'print' => 'Print document',
        'save' => 'Save count',
        'reopen' => 'Reopen',
    ],
    'statuses' => [
        'saved' => 'Saved',
        'reopened' => 'Reopened',
    ],
    'values' => [
        'not_available' => '—',
    ],
    'messages' => [
        'created' => 'Cashbox count saved successfully.',
        'updated' => 'Cashbox count updated successfully.',
        'reopened' => 'Cashbox count reopened for correction.',
        'reopen_required' => 'Reopen the cashbox count before changing it.',
    ],
];
