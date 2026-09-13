<?php

return [
    'self_service' => ['new_request' => 'New Request', 'my_requests' => 'My Requests', 'empty' => 'You have not submitted any requests yet.'],
    'admin' => ['title' => 'HR Requests', 'empty' => 'No matching requests.'],
    'types' => ['leave' => 'Leave', 'attendance_adjustment' => 'Attendance Adjustment', 'overtime' => 'Overtime', 'remote_work' => 'Remote Work', 'salary_advance' => 'Salary Advance', 'device_asset' => 'Device or Asset', 'employment_letter' => 'Employment Letter', 'profile_update' => 'Profile Update', 'other' => 'Other Request'],
    'statuses' => ['submitted' => 'Under Review', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'],
    'labels' => ['type' => 'Request Type', 'subject' => 'Short Subject', 'from' => 'From', 'to' => 'To', 'minutes' => 'Minutes', 'amount' => 'Amount', 'currency' => 'Currency', 'details' => 'Request Details', 'status' => 'Status', 'leave_type' => 'Leave Type', 'requested_check_in' => 'Requested Check In', 'requested_check_out' => 'Requested Check Out', 'asset_type' => 'Device or Asset Type', 'letter_language' => 'Letter Language', 'profile_field' => 'Profile Field', 'profile_value' => 'New Value'],
    'letter_languages' => ['ar' => 'Arabic', 'en' => 'English'],
    'placeholders' => ['type' => 'Select request type', 'currency' => 'Select currency', 'resolution_notes' => 'Decision notes (required when rejecting)'],
    'actions' => ['submit' => 'Submit Request', 'cancel' => 'Cancel Request', 'approve' => 'Approve', 'reject' => 'Reject'],
    'messages' => ['created' => 'The request was sent to HR.', 'cancelled' => 'The request was cancelled.', 'reviewed' => 'The request decision was saved.', 'not_owned' => 'This request does not belong to your account.', 'cannot_cancel' => 'A decided request cannot be cancelled.', 'already_resolved' => 'This request was already decided.', 'self_review_not_allowed' => 'You cannot approve or reject your own request.'],
];
