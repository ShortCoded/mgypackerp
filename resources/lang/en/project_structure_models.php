<?php

return [
    'title' => 'Project Structure Models',
    'singular' => 'Project Structure Model',
    'create' => 'Create Project Structure Model',
    'edit' => 'Edit Project Structure Model',
    'view' => 'View Project Structure Model',
    'clone' => 'Clone Project Structure Model',
    'bulk_action' => 'Bulk action',
    'select_all' => 'Select all project structure models',

    'attributes' => [
        'doc_number' => 'Document Number',
        'doc_num' => 'Document No.',
        'name' => 'Name',
        'code' => 'Code',
        'short_name' => 'Short Name',
        'status' => 'Status',
        'notes' => 'Notes',
    ],

    'defaults' => [
        'clone_name' => ':name - Copy',
    ],

    'statuses' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],

    'trash' => [
        'filter_label' => 'Records',
        'active' => 'Active',
        'trashed' => 'Deleted',
        'all' => 'All',
        'restore' => 'Restore',
    ],

    'messages' => [
        'created' => 'Project structure model was created successfully.',
        'cloned' => 'Project structure model was cloned successfully.',
        'updated' => 'Project structure model was updated successfully.',
        'deleted' => 'Project structure model was deleted successfully.',
        'bulk_deleted' => ':count project structure models were deleted successfully.',
        'restored' => 'Project structure model was restored successfully.',
        'restore_not_allowed' => 'This project structure model is not deleted.',
        'restore_conflict' => 'This project structure model conflicts with an active record.',
        'code_used' => 'This code is already used by another active project structure model.',
        'short_name_used' => 'This short name is already used by another active project structure model.',
        'doc_number_unique' => 'This document number is already used by another active project structure model.',
        'clone_not_allowed' => 'The selected project structure model cannot be cloned.',
        'action_forbidden' => 'You do not have permission to complete this action.',
        'delete_confirm_title' => 'Delete project structure model?',
        'delete_confirm_text' => 'This project structure model will be moved to deleted records.',
        'delete_confirm_yes' => 'Yes, delete it',
        'bulk_delete_confirm_title' => 'Delete selected project structure models?',
        'bulk_delete_confirm_text' => 'You are about to delete :count project structure models.',
        'bulk_delete_confirm_yes' => 'Yes, delete selected',
        'restore_confirm_title' => 'Restore project structure model?',
        'restore_confirm_text' => 'This project structure model will be restored to active records.',
        'restore_confirm_yes' => 'Yes, restore it',
    ],

    'document_number_settings' => [
        'updated_successfully' => 'Project structure model document number settings were updated successfully.',
    ],
];
