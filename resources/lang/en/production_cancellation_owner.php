<?php

return [
    'title' => 'Executed production cancellation owners', 'help' => 'Each proposal controls one run, one material document or the remaining order. Originals and prior accounting and payroll evidence remain available. No linked document is reversed automatically.',
    'owner_order' => 'Recover dependent sales/stock owners first, then warehouse receipt and handover, downstream output allocation and progress, stage transfers in reverse route order, conversion/payroll owners, and incorrect material documents. Return actual unused materials through the native material return. Cancel each recovered run before cancelling its order.',
    'document_error' => 'Cancel a documented error after full owner recovery', 'stop_remaining' => 'Short-close the remaining order and retain actual completed production',
    'material_document_error' => 'Reverse this incorrect consumption / waste document', 'reason' => 'Reason', 'evidence' => 'Actual measurement and owner evidence',
    'confirmed' => 'I confirm the selected treatment matches actual production and each required owner has been settled independently.',
    'material_confirmed' => 'The original document recorded a proven error: the stated consumption / waste did not actually occur. Actual waste must remain expensed; actual unused materials require a real return document.',
    'prepare' => 'Prepare owner proposal', 'approve' => 'Approve this owner only', 'reject' => 'Reject proposal', 'prepared' => 'Prepared', 'applying' => 'Applying', 'approved' => 'Approved', 'rejected' => 'Rejected',
    'recover_first' => 'Recover existing owners first. A document-error cancellation requires zero corrected output and receipt, zero WIP/conversion costs, all unused materials returned, and no active payroll, expense or outstanding material request.',
    'close_runs_first' => 'Close actual runs through their native completion owners, or cancel each fully recovered run independently, before preparing the order owner.',
    'stale' => 'Source evidence changed. Reject and prepare this proposal again.', 'pending' => 'Resolve the existing owner proposal first.',
    'migration_required' => 'Activate the production cancellation owner migration before using this action.',
];
