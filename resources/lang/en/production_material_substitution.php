<?php

return [
    'title' => 'Unconsumed material substitution',
    'help' => 'Prepare one run-local recipe exception. A different user with recipe edit, correction approval and material issue permissions must approve the technical evidence. Approval returns the original material and issues the replacement together. The master recipe and original issue stay in the audit trail.',
    'original' => 'Original material', 'replacement' => 'Replacement material', 'reason' => 'Recording correction reason',
    'prepare' => 'Prepare substitution', 'approve' => 'Approve recipe exception and apply', 'reject' => 'Reject',
    'prepared' => 'Substitution prepared for independent recipe approval.', 'approved' => 'Original returned and approved replacement issued.', 'rejected' => 'Substitution rejected.',
    'history' => 'Substitution history', 'evidence' => 'Technical recipe approval evidence',
    'select_replacement' => 'Select replacement material', 'technical_approval' => 'Technical recipe approval', 'cost_current' => 'Current run material cost',
    'confirm' => 'I approve this replacement and quantity for this run after reviewing its technical suitability.',
    'quantity' => 'Use a positive quantity from a fully issued, unconsumed requirement without pending reservations or material requests. Each original requirement may be substituted once.',
    'active' => 'Substitution requires a single running or held run without a shared batch or an existing execution correction.',
    'dependencies' => 'Material has downstream output, quality, consumption, waste, receipt, expense, payroll approval, overhead or valuation activity. Recover it through its owning workflow first.',
    'compatibility' => 'Choose a different active raw material in the same company and base unit. Serial-tracked and expiry-tracked materials are outside this substitution workflow.',
    'independent' => 'The preparer cannot approve their own recipe exception.', 'stale' => 'The proposal, source, replacement stock or posting date changed. Reject and prepare a fresh substitution.',
    'pending' => 'Reject the existing prepared substitution before preparing a different one.', 'state' => 'This substitution is no longer awaiting approval.',
    'reason_required' => 'Enter a correction reason.', 'evidence_required' => 'Enter technical evidence approving the replacement for this run.',
    'valuation' => 'Both material movements require complete native valuation before substitution can finish.',
    'specific' => 'Specific-identification stock requires explicit receipt-layer substitution approval and is outside this workflow.',
    'lineage' => 'The material requirement must retain its original run, order and order line.',
    'cost_before' => 'Run material cost before', 'cost_after' => 'Run material cost after', 'documents' => 'Linked return / replacement issue',
    'permissions' => 'Recipe edit, correction approval and material issue permissions are required to approve.',
];
