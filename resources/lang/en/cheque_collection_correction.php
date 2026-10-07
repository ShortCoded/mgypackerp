<?php

return [
    'title' => 'Correct collected incoming cheque', 'help' => 'Prepare an independent full collection correction using actual bank evidence. The original collection date, receipt, ledger and settlement history remain intact. This action records a verified bank reversal or a documented collection-entry error; it does not instruct the bank or claim a physical return of the cheque.',
    'treatment' => 'Supported treatment', 'bank_reversal' => 'Actual full bank reversal', 'collection_entry_error' => 'Documented error in the collection entry',
    'bank_reference' => 'Bank statement / reversal reference', 'reason' => 'Reason', 'evidence' => 'Actual bank and collection evidence',
    'confirmed' => 'I confirm that the bank evidence proves this full reversal or the original collection entry was incorrect. No bank transfer is performed by this form.',
    'prepare' => 'Prepare correction', 'approve' => 'Approve native receipt reversal', 'reject' => 'Reject proposal', 'prepared' => 'Prepared', 'approved' => 'Approved', 'rejected' => 'Rejected',
    'journal' => 'Original and reversal journals', 'evidence_required' => 'Provide the actual bank reference, reason and evidence for the full original collection.',
    'native_receipt_required' => 'A unique native posted customer receipt with matching cheque, customer, currency and amount is required. Reverse active withholding settlements independently first. A collected status alone is not proof of a cash ledger owner.',
    'stale' => 'The source or posting period changed. Reject and prepare the correction again.', 'pending' => 'Resolve the existing collection correction first.',
    'independent' => 'The approver must be independent of the preparer.', 'invalid_owner' => 'The original collection, native reversal or settlement history does not reconcile.',
    'migration_required' => 'Activate the cheque collection correction migration before using this action.',
];
