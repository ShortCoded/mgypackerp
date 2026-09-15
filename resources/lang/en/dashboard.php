<?php

return [
    'personal' => [
        'title' => 'My work dashboard',
        'summary' => 'You have :required open items, including :overdue overdue and :approvals awaiting your decision.',
        'last_updated' => 'Last updated: :time', 'stale' => 'Update failed; showing the last successful data.',
        'required_work' => 'Required from me', 'recent_updates' => 'Recent updates for me',
        'empty_work' => 'No open work is currently assigned to you.', 'empty_updates' => 'No recent updates.',
        'source_unavailable' => '“:source” is unavailable and was not shown as a misleading zero.',
        'cards' => ['open_tasks' => 'Open tasks', 'due_today' => 'Due today', 'overdue' => 'Overdue', 'approvals' => 'Awaiting my decision', 'unread' => 'New notifications'],
        'meta' => ['open_tasks' => 'From current task states', 'due_today' => 'Due during today', 'overdue' => 'Still open after due time', 'approvals' => 'No requests await your decision', 'approvals_oldest' => 'Oldest has waited :time', 'unread' => 'Reading notifications does not complete work'],
        'sources' => [
            'purchase_requisitions' => 'Purchase requisitions to approve', 'purchase_orders' => 'Purchase orders to approve',
            'material_requests' => 'Material requests to approve', 'quality' => 'Inspections to review',
            'expenses' => 'Amount requests to approve', 'expenses_payment' => 'Amount requests ready for payment',
            'maintenance' => 'Maintenance orders to approve', 'sales_orders' => 'Sales orders to approve',
            'credit_holds' => 'Credit-held sales orders', 'sales_returns' => 'Returns to authorize',
            'return_receipts' => 'Returns ready to receive', 'return_inspections' => 'Returns to inspect',
            'hr_requests' => 'HR requests to review',
        ],
        'work' => ['document' => 'Document :document', 'waiting_since' => 'Waiting :time', 'task' => 'Task assigned to you', 'due' => 'Due: :time', 'no_due_date' => 'No due date', 'open' => 'Open'],
    ],
    'title' => 'Dashboard',
    'create_something_beautiful' => 'Create Something Beautiful.',
    'getting_started' => 'Getting started',
    'plastics' => [
        'title' => 'Dashboard',
        'last_updated' => 'Last updated: :time',
        'actions' => [
            'open' => 'Open',
        ],
        'context' => [
            'not_selected' => 'Not selected',
            'period_dates' => ':from - :to',
        ],
        'sections' => [
            'operational_overview' => 'Operational Overview',
            'product_master' => 'Product and Material Master Data',
            'purchasing' => 'Purchasing and Suppliers',
            'inventory' => 'Inventory and Stores',
            'sales' => 'Sales and Customers',
            'follow_up' => 'Operational Follow-up',
            'attention' => 'Needs Attention',
            'quick_actions' => 'Quick Actions',
            'charts' => 'Data Overview',
        ],
        'metrics' => [
            'products' => [
                'title' => 'Active Products',
                'meta' => 'Product records only',
            ],
            'raw_materials' => [
                'title' => 'Active Raw Materials',
                'meta' => 'Raw material records only',
            ],
            'packaging_materials' => [
                'title' => 'Active Packaging Materials',
                'meta' => 'Packaging material records only',
            ],
            'products_with_bom' => [
                'title' => 'Products With BOM',
                'meta' => ':without without components',
            ],
            'bom_lines' => [
                'title' => 'BOM Component Lines',
                'meta' => 'Active component rows',
            ],
            'suppliers' => [
                'title' => 'Active Suppliers',
                'meta' => 'Company supplier records',
            ],
            'purchase_invoices' => [
                'title' => 'Purchase Invoices',
                'meta' => ':count unpaid or partially paid this period',
            ],
            'purchase_requisitions' => [
                'title' => 'Purchase Requests',
                'meta' => ':count awaiting approval this period',
            ],
            'purchase_orders' => [
                'title' => 'Purchase Orders',
                'meta' => ':count draft this period',
            ],
            'stores' => [
                'title' => 'Branch Stores',
                'meta' => 'Current branch store records',
            ],
            'opening_stocks' => [
                'title' => 'Opening Stock Docs',
                'meta' => ':count approved this period',
            ],
            'unpriced_receipts' => [
                'title' => 'Receipts Awaiting Pricing',
                'meta' => 'Unpriced inventory receipts this period',
            ],
            'customers' => [
                'title' => 'Active Customers',
                'meta' => 'Company customer records',
            ],
            'quotations' => [
                'title' => 'Sales Quotations',
                'meta' => ':count accepted this period',
            ],
            'my_tasks' => [
                'title' => 'My Open Tasks',
                'meta' => ':count overdue',
            ],
            'team_tasks' => [
                'title' => 'Team Open Tasks',
                'meta' => 'Visible active task records',
            ],
        ],
        'charts' => [
            'product_types' => 'Products and Materials by Type',
            'bom_coverage' => 'Product BOM Coverage',
            'raw_material_units' => 'Raw Materials by Unit',
            'packaging_material_units' => 'Packaging Materials by Unit',
            'task_status' => 'Tasks by Status',
        ],
        'chart_labels' => [
            'with_components' => 'With components',
            'without_components' => 'Without components',
            'unspecified' => 'Unspecified',
        ],
        'alerts' => [
            'products_without_components' => [
                'title' => 'BOM coverage',
                'body' => ':count active products do not have component rows.',
            ],
            'unpriced_receipts' => [
                'title' => 'Inventory pricing',
                'body' => ':count inventory receipts are still unpriced.',
            ],
            'my_tasks_overdue' => [
                'title' => 'Task due dates',
                'body' => ':count of your open tasks are overdue.',
            ],
        ],
        'quick_actions' => [
            'product' => 'Product',
            'raw_material' => 'Raw Material',
            'packaging_material' => 'Packaging Material',
            'products_report' => 'Products Data Report',
            'task' => 'Task',
        ],
        'empty' => [
            'no_alerts' => 'No data-backed exceptions are currently visible.',
            'no_quick_actions' => 'No permitted quick actions.',
            'no_chart_data' => 'No data is currently available for charts.',
        ],
        'limitations' => [
            'context_required' => 'Select a company, branch, and financial period to view dashboard data.',
            'no_visible_metrics' => 'No dashboard metrics are visible for your current permissions.',
        ],
    ],
    'expanded' => [
        'title' => 'Enterprise Dashboard',
        'last_updated' => 'Last updated: :time',
        'range_label' => ':from to :to',
        'currency_unspecified' => 'Unspecified currency',
        'no_due_date' => 'No due date',
        'filters' => [
            'range' => 'Range',
            'date_from' => 'From',
            'date_to' => 'To',
        ],
        'ranges' => [
            'today' => 'Today',
            'last_7' => 'Last 7 days',
            'last_30' => 'Last 30 days',
            'current_period' => 'Current period',
            'custom' => 'Custom',
        ],
        'actions' => [
            'refresh' => 'Refresh',
            'open' => 'Open',
        ],
        'context' => [
            'not_selected' => 'Not selected',
            'period_dates' => ':from - :to',
        ],
        'sections' => [
            'exceptions' => 'Operational Exceptions',
            'quick_actions' => 'Quick Actions',
        ],
        'empty' => [
            'no_alerts' => 'No visible exceptions for the selected context.',
            'no_quick_actions' => 'No permitted quick actions.',
            'no_recent_records' => 'No recent records for the selected context.',
        ],
        'limitations' => [
            'context_required' => 'Select a company, branch, and financial period to view expanded dashboard data.',
            'no_visible_widgets' => 'No dashboard widgets are visible for your current permissions.',
        ],
        'validation' => [
            'date_range_invalid' => 'Enter a valid dashboard date range.',
            'date_to_before_from' => 'The end date must be on or after the start date.',
        ],
        'kpis' => [
            'purchase_orders' => [
                'title' => 'Purchase Orders',
                'meta' => ':count draft awaiting approval',
            ],
            'purchase_invoices' => [
                'title' => 'Purchase Invoices',
                'meta' => ':count unpaid or partially paid',
            ],
            'suppliers' => [
                'title' => 'Active Suppliers',
                'meta' => 'Company supplier records',
            ],
            'unpriced_receipts' => [
                'title' => 'Receipts Awaiting Pricing',
                'meta' => 'Quantity receipts not yet priced',
            ],
            'opening_stock' => [
                'title' => 'Opening Stock Docs',
                'meta' => ':count approved',
            ],
            'branch_storage' => [
                'title' => 'Branch Stores',
                'meta' => ':count halls in current branch',
            ],
            'bom_readiness' => [
                'title' => 'Items Without Components',
                'meta' => 'Finished and semi-finished items',
            ],
            'raw_material_readiness' => [
                'title' => 'Raw Materials Missing Units',
                'meta' => ':count missing category or origin',
            ],
            'customers' => [
                'title' => 'Active Customers',
                'meta' => 'Company customer records',
            ],
            'quotations' => [
                'title' => 'Quotations',
                'meta' => ':count accepted',
            ],
            'bank_accounts' => [
                'title' => 'Active Bank Accounts',
                'meta' => 'Counts only, not balances',
            ],
            'cashboxes' => [
                'title' => 'Active Cashboxes',
                'meta' => 'Current branch count',
            ],
            'opening_balances' => [
                'title' => 'Opening Balances',
                'meta' => ':count draft',
            ],
            'fixed_assets' => [
                'title' => 'Active Fixed Assets',
                'meta' => ':count missing depreciation setup',
            ],
            'my_tasks' => [
                'title' => 'My Open Tasks',
                'meta' => ':overdue overdue, :due_soon due soon',
            ],
            'team_tasks' => [
                'title' => 'Team Open Tasks',
                'meta' => 'Permission-wide task count',
            ],
        ],
        'charts' => [
            'purchase_order_status' => 'Purchase Order Status',
            'purchase_invoice_status' => 'Purchase Invoice Status',
            'quotation_status' => 'Quotation Status',
        ],
        'alerts' => [
            'purchase_orders_awaiting_approval' => [
                'title' => 'PO approval',
                'body' => ':count purchase orders are still in draft.',
            ],
            'purchase_orders_overdue' => [
                'title' => 'PO delivery',
                'body' => ':count approved purchase orders are past expected delivery with remaining quantity.',
            ],
            'unpriced_receipts' => [
                'title' => 'Inventory pricing',
                'body' => ':count quantity-only receipts are still unpriced.',
            ],
            'products_without_components' => [
                'title' => 'Item readiness',
                'body' => ':count finished or semi-finished items have no component rows.',
            ],
            'raw_materials_missing_metadata' => [
                'title' => 'Raw material data',
                'body' => ':units raw materials are missing units; :metadata are missing category or origin.',
            ],
            'factory_branches_without_storage' => [
                'title' => 'Factory setup',
                'body' => ':count active factory branches have no halls or no stores.',
            ],
            'opening_balances_draft' => [
                'title' => 'Opening balances',
                'body' => ':count opening balance documents are still draft.',
            ],
            'assets_missing_depreciation' => [
                'title' => 'Asset setup',
                'body' => ':count active depreciable assets are missing depreciation settings.',
            ],
            'my_tasks_overdue' => [
                'title' => 'Task due dates',
                'body' => ':count of your open tasks are overdue.',
            ],
        ],
        'recent' => [
            'purchase_order_totals' => 'PO Totals by Currency',
            'purchase_invoice_totals' => 'Purchase Invoice Totals by Currency',
            'purchase_orders' => 'Recent Purchase Orders',
            'purchase_invoices' => 'Recent Purchase Invoices',
            'unpriced_receipts' => 'Recent Unpriced Receipts',
            'quotations' => 'Recent Quotations',
            'fixed_assets' => 'Recent Fixed Assets',
            'my_tasks' => 'My Due Tasks',
        ],
        'quick_actions' => [
            'purchase_order' => 'Purchase Order',
            'purchase_invoice' => 'Purchase Invoice',
            'supplier' => 'Supplier',
            'unpriced_receipt' => 'Unpriced Receipt',
            'customer' => 'Customer',
            'quotation' => 'Quotation',
            'bank_account' => 'Bank Account',
            'cashbox' => 'Cashbox',
            'opening_balance' => 'Opening Balance',
            'fixed_asset' => 'Fixed Asset',
            'task' => 'Task',
        ],
        'sources' => [
            'purchase_orders' => 'Purchase Orders',
            'purchase_invoices' => 'Purchase Invoices',
            'suppliers' => 'Suppliers',
            'unpriced_inventory_receipts' => 'Unpriced Inventory Receipts',
            'opening_stock' => 'Opening Stock',
            'branch_storage' => 'Branch Stores and Halls',
            'product_components' => 'Products and Components',
            'raw_materials' => 'Raw Materials',
            'factory_branches' => 'Factory Branches',
            'customers' => 'Customers',
            'quotations' => 'Quotations',
            'bank_accounts' => 'Bank Accounts',
            'cashboxes' => 'Cashboxes',
            'opening_balances' => 'Opening Balances',
            'fixed_assets' => 'Fixed Assets',
            'my_tasks' => 'My Board Tasks',
            'team_tasks' => 'Team Board Tasks',
        ],
    ],
];
