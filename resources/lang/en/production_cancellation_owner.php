<?php

return [
    'title' => 'Production cancellation or closing requests', 'help' => 'Each request covers one run, one material document or the remaining production order and requires review and approval by another user before execution. Originals and prior accounting and payroll evidence remain available. No linked document is reversed automatically.',
    'owner_order' => 'Settle the effects of linked sales and stock documents first, then warehouse receipt and production handover, downstream output allocation and progress, stage transfers in reverse route order, conversion cost and payroll documents, and incorrect material documents. Return actual unused materials through the original material return workflow. Cancel each settled run before cancelling its order.',
    'document_error' => 'Cancel a documented error after fully settling its effects', 'stop_remaining' => 'Close the remaining order and retain actual completed production',
    'material_document_error' => 'Reverse this incorrect consumption / waste document', 'reason' => 'Reason', 'evidence' => 'Actual measurement and evidence of settling linked documents',
    'confirmed' => 'I confirm the selected treatment matches actual production and the effects of each linked document have been settled through its own workflow.',
    'material_confirmed' => 'The original document recorded a proven error: the stated consumption / waste did not actually occur. Actual waste must remain expensed; actual unused materials require a real return document.',
    'prepare' => 'Prepare request for review', 'approve' => 'Approve request for this document', 'reject' => 'Reject request', 'prepared' => 'Awaiting review', 'applying' => 'Executing', 'approved' => 'Approved', 'rejected' => 'Rejected',
    'recover_first' => 'Settle existing effects first. A document-error cancellation requires zero corrected output and receipt, zero work in progress and conversion costs, all unused materials returned, and no active payroll, expense or outstanding material request.',
    'close_runs_first' => 'Complete actual runs through the run screen, or cancel each fully settled run separately, before preparing the order cancellation or closing request.',
    'stale' => 'The document data or request status changed. Review the data and status before continuing.', 'pending' => 'This document already has an active or approved request. Review it before preparing another request.',
    'migration_required' => 'Production cancellation requests are unavailable until the required database update is completed.',
];
