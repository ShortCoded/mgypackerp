<?php

return [
    'title' => 'Sales Return Correction in a Later Period',
    'help' => 'Recover each existing credit allocation or refund through its own approval first. Then propose the corrected quantities. A different reviewer approves the reversals and creates one replacement pending authorization.',
    'source_period' => 'Original period', 'target_period' => 'Posting period', 'posting_date' => 'Correction date',
    'dependencies' => 'Credit uses to recover first', 'allocation' => 'Recover credit allocation', 'refund' => 'Recover customer refund', 'return' => 'Correct return and create replacement',
    'reason' => 'Correction reason', 'prepare' => 'Submit correction for review', 'approve' => 'Approve and execute', 'reject' => 'Reject proposal',
    'approval_reason' => 'Review decision and supporting reason', 'replacement_quantity' => 'Replacement return quantity',
    'history' => 'Proposals and executed corrections', 'prepared' => 'Awaiting independent review', 'approved' => 'Correction executed', 'rejected' => 'Proposal rejected',
    'preparer' => 'Prepared by', 'approver' => 'Approved by', 'replacement' => 'Replacement return pending its normal approval',
    'journals' => 'Original accounting entries', 'documents' => 'Original stock documents',
    'stale' => 'The source or proposal changed. Refresh the plan and submit a new proposal.',
    'independent' => 'A different authorized user must review and approve this proposal with a reason.',
    'recover_first' => 'Recover all applied credit allocations and paid refunds separately before proposing the return correction.',
    'reject_existing' => 'A different proposal is awaiting review. Reject it before proposing another correction.',
    'target_invalid' => 'Choose an allowed open period after the original closed period and a correction date within it. The source period must stay closed.',
    'source_invalid' => 'The original return or its accounting/stock evidence is inconsistent. Review the original documents before correction.',
    'replacement_invalid' => 'Enter valid replacement quantities for the original return lines, with at least one positive quantity.',
];
