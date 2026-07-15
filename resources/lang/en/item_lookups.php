<?php

return [
    'bulk_action' => 'Bulk action',
    'select_all' => 'Select all records',
    'selected_records' => 'Selected records',
    'defaults' => [
        'clone_name' => 'Copy of :name',
    ],
    'document_number_control' => [
        'helper' => 'Leave empty for automatic generation. Prefix and padding are applied automatically.',
        'placeholder' => 'Auto',
    ],
    'fields' => [
        'name' => 'Name',
        'notes' => 'Notes',
        'status' => 'Status',
    ],
    'titles' => [
        'clone' => 'Clone Record',
    ],
    'trash' => [
        'active' => 'Actual records',
        'all' => 'All records',
        'filter_label' => 'Records',
        'restore' => 'Restore',
        'restore_confirm_text' => 'Are you sure you want to restore this record?',
        'restore_confirm_title' => 'Restore record',
        'restore_confirm_yes' => 'Yes, restore',
        'trashed' => 'Trashed records',
        'view_forbidden' => 'You are not allowed to view deleted records.',
    ],
    'statuses' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],
    'messages' => [
        'action_forbidden' => 'You do not have permission to use this save action.',
        'bulk_deleted' => ':count records deleted successfully.',
        'bulk_delete_confirm_text' => 'You are about to delete :count records.',
        'bulk_delete_confirm_title' => 'Delete selected records?',
        'bulk_delete_confirm_yes' => 'Yes, delete selected',
        'clone_not_allowed' => 'This record cannot be cloned.',
        'cloned' => 'Record cloned successfully.',
        'created' => 'Record created successfully.',
        'delete_confirm_text' => 'This action cannot be undone.',
        'delete_confirm_title' => 'Delete record?',
        'delete_confirm_yes' => 'Yes, delete it',
        'deleted' => 'Record deleted successfully.',
        'no_rows_selected' => 'Select at least one record.',
        'restore_not_allowed' => 'This record cannot be restored.',
        'restore_conflict' => 'An active record with the same values already exists.',
        'restored_successfully' => 'Record restored successfully.',
        'updated' => 'Record updated successfully.',
    ],
    'validation' => [
        'doc_number_numeric' => 'Document number must contain digits only.',
        'doc_number_unique' => 'Document number already exists.',
        'name_unique' => 'Name already exists.',
    ],
];
