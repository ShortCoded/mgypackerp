<?php

return [
    'title' => 'Shift Assignments',
    'history' => 'Assignment History',
    'empty' => 'No shift assignments have been recorded.',
    'labels' => ['employee' => 'Employee', 'employees' => 'Employees', 'branch' => 'Branch', 'shift' => 'Shift', 'effective_from' => 'Effective From', 'effective_to' => 'Effective Until', 'status' => 'Status', 'actions' => 'Actions'],
    'actions' => ['assign' => 'Assign Shift', 'update_end' => 'Save End Date'],
    'status' => ['effective' => 'Effective', 'historical' => 'Historical / Scheduled'],
    'messages' => ['created' => 'The dated shift assignment was saved.', 'updated' => 'The assignment end date was updated.', 'overlap' => 'One or more employees already has an overlapping shift assignment.', 'employee_scope_invalid' => 'One or more employees is outside your active branch scope.', 'shift_unavailable' => 'The selected shift is not active.', 'assignment_unavailable' => 'The shift assignment is unavailable.', 'invalid_effective_to' => 'The end date cannot be before the assignment start date.'],
];
