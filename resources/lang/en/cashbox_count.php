<?php

return [
    'title' => 'Cashbox Count',
    'description' => 'Compare a physical cash count with the approved book balance for each cashbox and currency.',
    'worksheet_title' => 'Count worksheet',
    'worksheet_notice' => 'This worksheet calculates variances from live approved balances. The current schema has no persistent cash-count document or approval workflow, so entered physical amounts are not saved.',
    'filters_title' => 'Count scope',
    'results_count' => ':count balances',
    'no_results' => 'No approved cash balance exists in the selected scope.',
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
    ],
    'actions' => [
        'print' => 'Print worksheet',
    ],
];
