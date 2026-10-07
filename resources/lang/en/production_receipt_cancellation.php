<?php

return [
    'title' => 'Cancel finished-goods receipt',
    'help' => 'Cancel one erroneous receipt by an exact stock and journal reversal. The original document and production evidence remain available. An independent approver must authorize the reversal.',
    'completed_evidence_required' => 'This action requires a completed run using output evidence.',
    'already_cancelled' => 'This receipt is already cancelled by reversal or is not posted.',
    'stock_used' => 'Received stock has been sold, transferred or consumed. Resolve its downstream documents first.',
    'downstream_stage' => 'A later production stage depends on this run. Resolve it through its own workflow first.',
    'reservation_ambiguous' => 'A used or shared finished-goods reservation cannot be assigned safely to this receipt. Resolve the sales reservation first.',
    'receipt_only_recovery' => 'Receipt correction is open. Only corrected finished-goods receipts and re-completion are allowed; production output, material use and payroll evidence are preserved.',
    'prepare' => 'Prepare receipt cancellation',
    'approve' => 'Approve cancellation and reverse',
    'cancelled' => 'Cancelled with an audited reversal',
    'retained' => 'Original receipt retained; consumption, output, shift and payroll evidence preserved.',
    'recovery' => 'Complete the corrected receipt, then close the run again.',
];
