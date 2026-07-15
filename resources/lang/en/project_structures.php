<?php

return [
    'title' => 'Project Structures',
    'singular' => 'Project Structure',
    'create' => 'Create Project Structure',
    'edit' => 'Edit Project Structure',
    'view' => 'View Project Structure',
    'clone' => 'Clone Project Structure',
    'tree_view' => 'Tree View',
    'list_view' => 'List View',
    'show_tree' => 'Show Tree',
    'show_list' => 'Show List',
    'expand_all' => 'Expand All',
    'collapse_all' => 'Collapse All',
    'empty_tree' => 'No project structures found.',
    'bulk_action' => 'Bulk action',
    'select_all' => 'Select all project structures',

    'attributes' => [
        'doc_number' => 'Document Number',
        'doc_num' => 'Document No.',
        'name' => 'Name',
        'code' => 'Code',
        'parent' => 'Parent',
        'no_parent' => 'Root',
        'status' => 'Status',
        'notes' => 'Notes',
        'sort_order' => 'Sort Order',
    ],

    'defaults' => [
        'clone_name' => ':name - Copy',
    ],

    'statuses' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],

    'actions' => [
        'expand_all' => 'Expand All',
        'collapse_all' => 'Collapse All',
        'expand_branch' => 'Expand Branch',
        'collapse_branch' => 'Collapse Branch',
    ],

    'trash' => [
        'filter_label' => 'Records',
        'active' => 'Active',
        'trashed' => 'Deleted',
        'all' => 'All',
        'restore' => 'Restore',
    ],

    'messages' => [
        'created' => 'Project structure was created successfully.',
        'cloned' => 'Project structure was cloned successfully.',
        'updated' => 'Project structure was updated successfully.',
        'deleted' => 'Project structure was deleted successfully.',
        'bulk_deleted' => ':count project structures were deleted successfully.',
        'restored' => 'Project structure was restored successfully.',
        'restore_not_allowed' => 'This project structure is not deleted.',
        'restore_conflict' => 'This project structure conflicts with an active record.',
        'parent_restore_unavailable' => 'Restore the parent structure first.',
        'delete_blocked_children' => 'Delete or move child structures before deleting this project structure.',
        'code_used' => 'This code is already used by another active project structure.',
        'doc_number_unique' => 'This document number is already used by another active project structure.',
        'self_parent' => 'A project structure cannot be its own parent.',
        'parent_cycle' => 'This parent would create a circular project structure.',
        'clone_not_allowed' => 'The selected project structure cannot be cloned.',
        'action_forbidden' => 'You do not have permission to complete this action.',
        'no_data_found' => 'No project structures found.',
        'delete_confirm_title' => 'Delete project structure?',
        'delete_confirm_text' => 'This project structure will be moved to deleted records.',
        'delete_confirm_yes' => 'Yes, delete it',
        'bulk_delete_confirm_title' => 'Delete selected project structures?',
        'bulk_delete_confirm_text' => 'You are about to delete :count project structures.',
        'bulk_delete_confirm_yes' => 'Yes, delete selected',
        'restore_confirm_title' => 'Restore project structure?',
        'restore_confirm_text' => 'This project structure will be restored to active records.',
        'restore_confirm_yes' => 'Yes, restore it',
    ],

    'document_number_settings' => [
        'updated_successfully' => 'Project structure document number settings were updated successfully.',
    ],
];
