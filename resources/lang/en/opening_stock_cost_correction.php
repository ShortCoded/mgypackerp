<?php

return [
    'title' => 'Opening Stock Cost Correction',
    'help' => 'Enter the correct unit cost and supporting reference for each line. Review the value difference and its effect on stock, issues, production and sales. A different user approves the correction in the open financial period. Original quantities and documents remain in history.',
    'source' => 'Original document',
    'reference' => 'Correct cost reference',
    'reason' => 'Correction reason',
    'posting_date' => 'Cost difference posting date',
    'counterpart' => 'Cost difference counterpart account',
    'new_unit_cost' => 'Corrected unit cost',
    'prepare' => 'Prepare and review correction impact',
    'history' => 'Correction proposals and approval history',
    'prepared_by' => 'Prepared by',
    'approved_by' => 'Approved by',
    'approval_reference' => 'Correction approval reference',
    'approve' => 'Approve and post cost difference',
    'reject' => 'Reject proposal',
    'rejection_reason' => 'Rejection reason',
    'independent' => 'A different user with approval permission must approve this proposal.',
    'pending' => 'A proposal is awaiting review. Approve or reject it before preparing another correction.',
    'messages' => [
        'prepared' => 'The correction proposal and its impact are ready for independent review.',
        'approved' => 'The cost difference was approved and posted, preserving original quantities and history.',
        'rejected' => 'The proposal was rejected and the review history retained.',
    ],
    'statuses' => ['pending' => 'Awaiting review', 'approved' => 'Approved', 'rejected' => 'Rejected'],
];
