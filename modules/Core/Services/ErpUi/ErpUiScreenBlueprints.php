<?php

namespace Modules\Core\Services\ErpUi;

use Illuminate\Support\Str;

class ErpUiScreenBlueprints
{
    /**
     * @param  array<string, mixed>  $module
     * @param  array<string, mixed>  $screen
     * @return array<string, mixed>
     */
    public function build(array $module, array $screen, int $screenOrder): array
    {
        $moduleKey = (string) $module['module'];
        $slug = trim((string) ($screen['slug'] ?? Str::kebab((string) $screen['key'])), '/');
        $routeSegment = trim((string) ($module['route_segment'] ?? $moduleKey), '/');
        $routeName = trim((string) ($module['route_name'] ?? str_replace('_', '-', $moduleKey)), '.');
        $permissionModule = trim((string) ($module['permission_prefix'] ?? $moduleKey), '.');
        $permissionResource = str_replace(['/', '-'], ['.', '_'], $slug);
        $profile = (string) ($screen['profile'] ?? 'document');
        $kind = (string) ($screen['kind'] ?? (in_array($profile, ['report', 'inquiry'], true) ? 'report' : 'resource'));
        $classification = (string) ($screen['classification'] ?? 'WORKING_REAL_SCREEN');
        $actions = $screen['actions'] ?? $this->actionsFor($profile);
        $modes = $screen['modes'] ?? ($kind === 'report'
            ? ['index', 'data']
            : ['index', 'data', 'create', 'view', 'edit', 'clone']);
        $tabs = $screen['tabs'] ?? $this->tabsFor($moduleKey, $profile, (string) $screen['key']);
        $indexColumns = $screen['index_columns'] ?? $this->indexColumnsFor($moduleKey, $profile);

        return [
            ...$screen,
            'key' => (string) $screen['key'],
            'module' => $moduleKey,
            'module_title' => $module['title'],
            'kind' => $kind,
            'profile' => $profile,
            'classification' => $classification,
            'slug' => $slug,
            'route_path' => 'admin/'.$routeSegment.'/'.$slug,
            'route_name_prefix' => 'admin.'.$routeName.'.'.str_replace('/', '.', $slug),
            'permission_prefix' => $screen['permission_prefix'] ?? $permissionModule.'.'.$permissionResource,
            'menu_label' => (string) ($module['menu']['label'] ?? $moduleKey),
            'menu_title' => $module['menu']['title'] ?? $module['title'],
            'menu_icon' => (string) ($module['menu']['icon'] ?? $module['icon'] ?? 'folder'),
            'menu_order' => (int) ($module['menu']['order'] ?? $module['order'] ?? 999),
            'group' => (string) ($screen['group'] ?? 'operations'),
            'group_definition' => $module['groups'][$screen['group'] ?? 'operations'] ?? [
                'title' => ['en' => 'Operations', 'ar' => 'العمليات'],
                'icon' => 'folder-open',
                'order' => 999,
            ],
            'order' => (int) ($screen['order'] ?? $screenOrder),
            'icon' => (string) ($screen['icon'] ?? $this->iconFor($profile)),
            'actions' => array_values(array_unique($actions)),
            'declared_actions' => array_values(array_unique($screen['actions'] ?? [])),
            'modes' => array_values(array_unique($modes)),
            'index_columns' => $indexColumns,
            'tabs' => $tabs,
            'statuses' => $screen['statuses'] ?? $this->statuses(),
            'show_document_number' => (bool) ($screen['show_document_number'] ?? $profile !== 'report'),
            'show_document_number_settings' => (bool) ($screen['show_document_number_settings'] ?? in_array('document_number_settings.update', $actions, true)),
            'show_scope_filter' => (bool) ($screen['show_scope_filter'] ?? in_array('view_trashed', $actions, true)),
            'show_checkbox' => (bool) ($screen['show_checkbox'] ?? ($profile !== 'report')),
            'show_clone' => (bool) ($screen['show_clone'] ?? in_array('clone', $actions, true)),
            'show_attachments' => (bool) ($screen['show_attachments'] ?? $profile !== 'report'),
            'show_audit' => (bool) ($screen['show_audit'] ?? $profile !== 'report'),
            'double_click_mode' => (string) ($screen['double_click_mode'] ?? 'view'),
            'status' => 'Real screen metadata',
        ];
    }

    /**
     * @return list<string>
     */
    private function actionsFor(string $profile): array
    {
        if ($profile === 'report') {
            return ['view', 'print', 'export'];
        }

        if ($profile === 'setup') {
            return ['view', 'create', 'edit', 'clone', 'delete', 'view_trashed', 'restore', 'print', 'export', 'document_number.control', 'document_number_settings.update'];
        }

        if ($profile === 'master') {
            return ['view', 'create', 'edit', 'clone', 'delete', 'view_trashed', 'restore', 'print', 'export', 'document_number.control', 'document_number_settings.update'];
        }

        if ($profile === 'inquiry') {
            return ['view', 'print', 'export'];
        }

        return ['view', 'create', 'edit', 'clone', 'delete', 'view_trashed', 'restore', 'approve', 'cancel', 'close', 'post', 'print', 'export', 'document_number.control', 'document_number_settings.update'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function indexColumnsFor(string $module, string $profile): array
    {
        if ($profile === 'report' || $profile === 'inquiry') {
            return [
                $this->column('doc_num', 'Document Number', 'رقم المستند', true),
                $this->column('document_date', 'Date', 'التاريخ'),
                $this->column('description', 'Description', 'البيان'),
                $this->column('status', 'Status', 'الحالة'),
                $this->column('amount', 'Amount', 'المبلغ'),
            ];
        }

        if ($profile === 'master' || $profile === 'setup') {
            return [
                $this->column('select', '', '', true, false),
                $this->column('doc_num', 'Document Number', 'رقم المستند', true),
                $this->column('name', 'Name', 'الاسم'),
                $this->column('status', 'Status', 'الحالة'),
                $this->column('notes', 'Notes', 'ملاحظات'),
                $this->column('actions', 'Actions', 'الإجراءات', true, false),
            ];
        }

        $partyLabel = match ($module) {
            'sales' => ['Customer', 'العميل'],
            'purchases' => ['Supplier', 'المورد'],
            'production' => ['Product / Work Order', 'المنتج / أمر التشغيل'],
            'quality' => ['Product / Material', 'المنتج / الخامة'],
            'maintenance' => ['Asset / Machine', 'الأصل / الماكينة'],
            'finance' => ['Counterparty', 'الطرف المقابل'],
            'fixed_assets' => ['Asset', 'الأصل'],
            'hr' => ['Employee', 'الموظف'],
            default => ['Reference', 'المرجع'],
        };

        return [
            $this->column('select', '', '', true, false),
            $this->column('doc_num', 'Document Number', 'رقم المستند', true),
            $this->column('document_date', 'Document Date', 'تاريخ المستند'),
            $this->column('party', $partyLabel[0], $partyLabel[1]),
            $this->column('reference_number', 'Reference Number', 'رقم المرجع'),
            $this->column('status', 'Status', 'الحالة'),
            $this->column('actions', 'Actions', 'الإجراءات', true, false),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tabsFor(string $module, string $profile, string $screenKey): array
    {
        if ($profile === 'report' || $profile === 'inquiry') {
            return $this->reportTabs();
        }

        if ($profile === 'master') {
            return $this->masterTabs();
        }

        if ($profile === 'setup') {
            return $this->setupTabs();
        }

        if ($module === 'quality' && (str_contains($screenKey, 'inspection') || str_contains($screenKey, 'test_result'))) {
            return $this->qualityInspectionTabs();
        }

        if ($module === 'maintenance' && str_contains($screenKey, 'work_order')) {
            return $this->maintenanceWorkOrderTabs();
        }

        return $this->documentTabs($module, $screenKey);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function masterTabs(): array
    {
        return [
            $this->tab('basic', 'Basic Information', 'البيانات الأساسية', [
                $this->section('identity', 'Record Information', 'بيانات السجل', [
                    $this->field('doc_num', 'Document Number', 'رقم المستند', 'document_number', 3, false, ['direction' => 'ltr']),
                    $this->field('name', 'Name', 'الاسم', 'text', 6, true),
                    $this->field('code', 'Code', 'الكود', 'barcode', 3, false, ['direction' => 'ltr']),
                    $this->field('status', 'Status', 'الحالة', 'status', 3, true, ['options' => $this->statuses()]),
                    $this->field('effective_date', 'Effective Date', 'تاريخ السريان', 'date', 3),
                    $this->field('color', 'Display Color', 'لون العرض', 'color', 3),
                    $this->field('notes', 'Notes', 'ملاحظات', 'textarea', 12),
                ]),
            ]),
            $this->attachmentsTab(),
            $this->historyTab(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function setupTabs(): array
    {
        return [
            $this->tab('basic', 'Basic Information', 'البيانات الأساسية', [
                $this->section('context', 'Configuration Context', 'نطاق الإعداد', [
                    $this->field('doc_num', 'Document Number', 'رقم المستند', 'document_number', 3, false, ['direction' => 'ltr']),
                    $this->field('name', 'Configuration Name', 'اسم الإعداد', 'text', 6, true),
                    $this->field('company_doc_num', 'Company', 'الشركة', 'ajax_select2', 3, false, ['endpoint' => 'admin.select2.companies']),
                    $this->field('branch_doc_num', 'Branch', 'الفرع', 'ajax_select2', 3, false, ['endpoint' => 'admin.select2.branches']),
                    $this->field('effective_from', 'Effective From', 'ساري من', 'date', 3),
                    $this->field('is_active', 'Active', 'نشط', 'switch', 3, false, ['default_visual' => true]),
                    $this->field('notes', 'Notes', 'ملاحظات', 'textarea', 12),
                ]),
            ]),
            $this->tab('details', 'Details', 'التفاصيل', [], [
                $this->repeater('parameters', 'Configuration Parameters', 'معاملات الإعداد', [
                    $this->field('parameter', 'Parameter', 'المعامل', 'text', 4, true),
                    $this->field('value', 'Value', 'القيمة', 'text', 4),
                    $this->field('description', 'Description', 'الوصف', 'textarea', 4),
                ]),
            ]),
            $this->attachmentsTab(),
            $this->historyTab(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentTabs(string $module, string $screenKey): array
    {
        $tabs = [
            $this->tab('basic', 'Basic Information', 'البيانات الأساسية', [
                $this->section('document', 'Document Header', 'رأس المستند', $this->documentHeaderFields($module)),
            ]),
            $this->tab('details', 'Details', 'التفاصيل', [], [
                $this->detailRepeater($module, $screenKey),
            ]),
        ];

        if (in_array($module, ['sales', 'purchases', 'finance'], true)) {
            $tabs[] = $this->tab('payments', 'Payments', 'المدفوعات', [], [
                $this->repeater('payment_schedule', 'Payment Schedule', 'جدول الدفعات', [
                    $this->field('due_date', 'Due Date', 'تاريخ الاستحقاق', 'date', 3),
                    $this->field('percentage', 'Percentage', 'النسبة', 'percentage', 3),
                    $this->field('amount', 'Amount', 'المبلغ', 'money', 3),
                    $this->field('notes', 'Notes', 'ملاحظات', 'text', 3),
                ]),
            ]);
        }

        if (in_array($module, ['sales', 'purchases', 'inventory', 'production'], true)) {
            $tabs[] = $this->tab('delivery', 'Delivery', 'التسليم', [], [
                $this->repeater('delivery_schedule', 'Delivery Schedule', 'جدول التسليم', [
                    $this->field('delivery_date', 'Delivery Date', 'تاريخ التسليم', 'date', 3),
                    $this->field('location', 'Location', 'الموقع', 'text', 3),
                    $this->field('quantity', 'Quantity', 'الكمية', 'decimal', 3),
                    $this->field('notes', 'Notes', 'ملاحظات', 'text', 3),
                ]),
            ]);
        }

        $tabs[] = $this->attachmentsTab();
        $tabs[] = $this->historyTab();

        return $tabs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentHeaderFields(string $module): array
    {
        $fields = [
            $this->field('doc_num', 'Document Number', 'رقم المستند', 'document_number', 3, false, ['direction' => 'ltr']),
            $this->field('document_date', 'Document Date', 'تاريخ المستند', 'date', 3, true),
            $this->field('company_doc_num', 'Company', 'الشركة', 'ajax_select2', 3, false, ['endpoint' => 'admin.select2.companies']),
            $this->field('financial_period_doc_num', 'Financial Period', 'الفترة المالية', 'ajax_select2', 3, false, ['endpoint' => 'admin.select2.financial-periods']),
            $this->field('branch_doc_num', 'Branch', 'الفرع', 'ajax_select2', 3, false, ['endpoint' => 'admin.select2.branches']),
            $this->field('reference_number', 'Reference Number', 'رقم المرجع', 'text', 3, false, ['direction' => 'ltr']),
            $this->field('responsible_user_doc_num', 'Responsible Employee / User', 'الموظف / المستخدم المسؤول', 'ajax_select2', 3, false, ['endpoint' => 'admin.select2.users']),
            $this->field('status', 'Status', 'الحالة', 'status', 3, true, ['options' => $this->statuses()]),
        ];

        $moduleFields = match ($module) {
            'sales' => [
                $this->field('customer_doc_num', 'Customer', 'العميل', 'ajax_select2', 4, true, ['endpoint' => 'admin.sales.select2.customers']),
                $this->field('sales_employee_doc_num', 'Sales Employee', 'موظف المبيعات', 'ajax_select2', 4, false, ['endpoint' => 'admin.select2.users']),
                $this->field('currency_doc_num', 'Currency', 'العملة', 'ajax_select2', 2, false, ['endpoint' => 'admin.select2.currencies']),
                $this->field('exchange_rate', 'Exchange Rate', 'سعر الصرف', 'decimal', 2, false, ['default_visual' => '1']),
            ],
            'purchases' => [
                $this->field('supplier_doc_num', 'Supplier', 'المورد', 'ajax_select2', 4, true, ['endpoint' => 'admin.purchases.select2.suppliers']),
                $this->field('purchase_type', 'Purchase Type', 'نوع الشراء', 'static_select', 4, false, ['options' => $this->genericOptions()]),
                $this->field('currency_doc_num', 'Currency', 'العملة', 'ajax_select2', 2, false, ['endpoint' => 'admin.select2.currencies']),
                $this->field('exchange_rate', 'Exchange Rate', 'سعر الصرف', 'decimal', 2, false, ['default_visual' => '1']),
            ],
            'inventory' => [
                $this->field('store_doc_num', 'Store', 'المخزن', 'empty_select2', 4, true),
                $this->field('hall_location_doc_num', 'Hall / Location', 'الصالة / الموقع', 'empty_select2', 4),
                $this->field('source_document_doc_num', 'Source Document', 'المستند المصدر', 'empty_select2', 4),
            ],
            'production' => [
                $this->field('source_sales_document_doc_num', 'Source Sales Document', 'مستند المبيعات المصدر', 'empty_select2', 4),
                $this->field('customer_doc_num', 'Customer', 'العميل', 'ajax_select2', 4, false, ['endpoint' => 'admin.sales.select2.customers']),
                $this->field('product_doc_num', 'Product', 'المنتج', 'empty_select2', 4, true),
                $this->field('planned_quantity', 'Planned Quantity', 'الكمية المخططة', 'decimal', 3, true),
                $this->field('machine_doc_num', 'Machine', 'الماكينة', 'empty_select2', 3),
                $this->field('mold_doc_num', 'Mold', 'الاسطمبة', 'empty_select2', 3),
                $this->field('planned_start', 'Planned Start', 'البداية المخططة', 'datetime', 3),
            ],
            'quality' => [
                $this->field('source_document_doc_num', 'Source Document', 'المستند المصدر', 'empty_select2', 4),
                $this->field('product_material_doc_num', 'Product / Material', 'المنتج / الخامة', 'empty_select2', 4, true),
                $this->field('inspection_plan_doc_num', 'Inspection Plan', 'خطة الفحص', 'empty_select2', 4),
            ],
            'maintenance' => [
                $this->field('asset_doc_num', 'Asset / Machine / Mold', 'الأصل / الماكينة / الاسطمبة', 'empty_select2', 4, true),
                $this->field('request_type', 'Request Type', 'نوع الطلب', 'static_select', 4, true, ['options' => $this->genericOptions()]),
                $this->field('priority', 'Priority', 'الأولوية', 'radio', 4, false, ['options' => $this->priorityOptions()]),
            ],
            'finance' => [
                $this->field('cashbox_bank_doc_num', 'Cashbox / Bank', 'الخزينة / البنك', 'empty_select2', 4, true),
                $this->field('counterparty_doc_num', 'Counterparty', 'الطرف المقابل', 'empty_select2', 4),
                $this->field('currency_doc_num', 'Currency', 'العملة', 'ajax_select2', 2, true, ['endpoint' => 'admin.select2.currencies']),
                $this->field('exchange_rate', 'Exchange Rate', 'سعر الصرف', 'decimal', 2, true, ['default_visual' => '1']),
                $this->field('amount', 'Amount', 'المبلغ', 'money', 4, true),
                $this->field('payment_method', 'Payment Method', 'طريقة الدفع', 'static_select', 4, true, ['options' => $this->genericOptions()]),
            ],
            'fixed_assets' => [
                $this->field('asset_doc_num', 'Asset', 'الأصل', 'empty_select2', 4, true),
                $this->field('asset_location_doc_num', 'Asset Location', 'موقع الأصل', 'empty_select2', 4),
                $this->field('asset_value', 'Asset Value', 'قيمة الأصل', 'money', 4),
            ],
            'costing' => [
                $this->field('product_doc_num', 'Product', 'المنتج', 'empty_select2', 4),
                $this->field('work_order_doc_num', 'Work Order', 'أمر التشغيل', 'empty_select2', 4),
                $this->field('cost_center_doc_num', 'Cost Center', 'مركز التكلفة', 'empty_select2', 4),
            ],
            'hr' => [
                $this->field('employee_doc_num', 'Employee', 'الموظف', 'empty_select2', 4, true),
                $this->field('from_date', 'From Date', 'من تاريخ', 'date', 4),
                $this->field('to_date', 'To Date', 'إلى تاريخ', 'date', 4),
            ],
            default => [],
        };

        return [...$fields, ...$moduleFields, $this->field('notes', 'Notes', 'ملاحظات', 'textarea', 12)];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailRepeater(string $module, string $screenKey): array
    {
        $fields = match ($module) {
            'sales' => [
                $this->field('product_service_doc_num', 'Product / Service', 'المنتج / الخدمة', 'empty_select2', 4, true),
                $this->field('description', 'Description', 'الوصف', 'text', 4),
                $this->field('unit_doc_num', 'Unit', 'الوحدة', 'ajax_select2', 2, false, ['endpoint' => 'admin.select2.item-units']),
                $this->field('quantity', 'Quantity', 'الكمية', 'decimal', 2, true),
                $this->field('unit_price', 'Unit Price', 'سعر الوحدة', 'money', 3),
                $this->field('discount', 'Discount', 'الخصم', 'percentage', 3),
                $this->field('tax', 'Tax', 'الضريبة', 'percentage', 3),
                $this->field('line_total', 'Line Total', 'إجمالي السطر', 'readonly_calculated', 3),
            ],
            'purchases' => [
                $this->field('product_service_doc_num', 'Product / Service', 'المنتج / الخدمة', 'empty_select2', 4, true),
                $this->field('source_request_doc_num', 'Source Request', 'طلب المصدر', 'empty_select2', 4),
                $this->field('unit_doc_num', 'Unit', 'الوحدة', 'ajax_select2', 2, false, ['endpoint' => 'admin.select2.item-units']),
                $this->field('quantity', 'Quantity', 'الكمية', 'decimal', 2, true),
                $this->field('unit_price', 'Unit Price', 'سعر الوحدة', 'money', 3),
                $this->field('discount', 'Discount', 'الخصم', 'percentage', 3),
                $this->field('tax', 'Tax', 'الضريبة', 'percentage', 3),
                $this->field('delivery_location', 'Delivery Location', 'مكان التسليم', 'text', 3),
            ],
            'inventory' => [
                $this->field('product_doc_num', 'Product', 'المنتج', 'empty_select2', 4, true),
                $this->field('unit_doc_num', 'Unit', 'الوحدة', 'ajax_select2', 2, false, ['endpoint' => 'admin.select2.item-units']),
                $this->field('quantity', 'Quantity', 'الكمية', 'decimal', 2, true),
                $this->field('batch_lot', 'Batch / Lot', 'التشغيلة / اللوط', 'barcode', 2),
                $this->field('location', 'Location', 'الموقع', 'text', 2),
                $this->field('notes', 'Notes', 'ملاحظات', 'text', 12),
            ],
            'production' => [
                $this->field('material_product_doc_num', 'Material / Product', 'الخامة / المنتج', 'empty_select2', 4, true),
                $this->field('required_quantity', 'Required Quantity', 'الكمية المطلوبة', 'decimal', 2, true),
                $this->field('unit_doc_num', 'Unit', 'الوحدة', 'ajax_select2', 2, false, ['endpoint' => 'admin.select2.item-units']),
                $this->field('machine_doc_num', 'Machine', 'الماكينة', 'empty_select2', 2),
                $this->field('mold_doc_num', 'Mold', 'الاسطمبة', 'empty_select2', 2),
            ],
            'quality' => $this->qualityCharacteristicFields(),
            'maintenance' => [
                $this->field('task', 'Task', 'المهمة', 'text', 4, true),
                $this->field('technician_doc_num', 'Technician', 'الفني', 'empty_select2', 3),
                $this->field('spare_part_doc_num', 'Spare Part', 'قطعة الغيار', 'empty_select2', 3),
                $this->field('quantity', 'Quantity', 'الكمية', 'decimal', 2),
                $this->field('planned_hours', 'Planned Hours', 'الساعات المخططة', 'decimal', 2),
                $this->field('completed', 'Completed', 'مكتملة', 'checkbox', 2),
            ],
            'finance' => [
                $this->field('source_document_doc_num', 'Source Document', 'المستند المصدر', 'empty_select2', 4),
                $this->field('due_amount', 'Due Amount', 'المبلغ المستحق', 'money', 3),
                $this->field('allocated_amount', 'Allocated Amount', 'المبلغ المخصص', 'money', 3),
                $this->field('notes', 'Notes', 'ملاحظات', 'text', 2),
            ],
            'costing' => [
                $this->field('cost_element_doc_num', 'Cost Element', 'عنصر التكلفة', 'empty_select2', 4, true),
                $this->field('basis', 'Allocation Basis', 'أساس التوزيع', 'static_select', 3, false, ['options' => $this->genericOptions()]),
                $this->field('quantity', 'Quantity', 'الكمية', 'decimal', 2),
                $this->field('rate', 'Rate', 'المعدل', 'money', 2),
                $this->field('calculated_cost', 'Calculated Cost', 'التكلفة المحسوبة', 'readonly_calculated', 3),
            ],
            default => [
                $this->field('description', 'Description', 'الوصف', 'text', 6, true),
                $this->field('quantity', 'Quantity', 'الكمية', 'decimal', 3),
                $this->field('notes', 'Notes', 'ملاحظات', 'text', 3),
            ],
        };

        $nestedRepeaters = [];

        if ($module === 'production' || str_contains($screenKey, 'project_allocation')) {
            $nestedRepeaters[] = $this->repeater('operations', 'Operations / Allocations', 'العمليات / التخصيصات', [
                $this->field('operation', 'Operation', 'العملية', 'text', 4, true),
                $this->field('resource', 'Resource', 'المورد', 'empty_select2', 4),
                $this->field('planned_quantity', 'Planned Quantity', 'الكمية المخططة', 'decimal', 4),
            ]);
        }

        if (in_array($module, ['sales', 'purchases'], true)) {
            $nestedRepeaters[] = $this->repeater('line_schedule', 'Line Schedule', 'جدول السطر', [
                $this->field('date', 'Date', 'التاريخ', 'date', 4),
                $this->field('quantity', 'Quantity', 'الكمية', 'decimal', 4),
                $this->field('location', 'Location', 'الموقع', 'text', 4),
            ]);
        }

        return $this->repeater('details', 'Document Details', 'تفاصيل المستند', $fields, $nestedRepeaters);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function qualityInspectionTabs(): array
    {
        return [
            $this->tab('basic', 'Basic Information', 'البيانات الأساسية', [
                $this->section('document', 'Inspection Header', 'رأس الفحص', $this->documentHeaderFields('quality')),
            ]),
            $this->tab('source', 'Source Document', 'المستند المصدر', [
                $this->section('source', 'Source Information', 'بيانات المصدر', [
                    $this->field('source_document_doc_num', 'Source Document', 'المستند المصدر', 'empty_select2', 6),
                    $this->field('source_line_reference', 'Source Line', 'سطر المصدر', 'text', 3),
                    $this->field('received_quantity', 'Received Quantity', 'الكمية المستلمة', 'decimal', 3),
                ]),
            ]),
            $this->tab('characteristics', 'Inspection Characteristics', 'خصائص الفحص', [], [
                $this->repeater('characteristics', 'Inspection Characteristics', 'خصائص الفحص', $this->qualityCharacteristicFields(), [
                    $this->repeater('characteristic_results', 'Sample Results', 'نتائج العينات', [
                        $this->field('sample_number', 'Sample Number', 'رقم العينة', 'text', 4),
                        $this->field('actual_result', 'Actual Result', 'النتيجة الفعلية', 'text', 4),
                        $this->field('pass_fail', 'Pass / Fail', 'مطابق / غير مطابق', 'static_select', 4, false, ['options' => $this->passFailOptions()]),
                    ]),
                ]),
            ]),
            $this->tab('samples', 'Samples', 'العينات', [], [$this->simpleResultRepeater('samples', 'Samples', 'العينات')]),
            $this->tab('results', 'Results', 'النتائج', [], [$this->simpleResultRepeater('results', 'Results', 'النتائج')]),
            $this->tab('defects', 'Defects', 'العيوب', [], [$this->simpleResultRepeater('defects', 'Defects', 'العيوب')]),
            $this->tab('decision', 'Decision', 'القرار', [
                $this->section('decision', 'Inspection Decision', 'قرار الفحص', [
                    $this->field('decision', 'Decision', 'القرار', 'radio', 6, true, ['options' => $this->passFailOptions()]),
                    $this->field('decision_notes', 'Decision Notes', 'ملاحظات القرار', 'textarea', 12),
                ]),
            ]),
            $this->attachmentsTab(),
            $this->historyTab(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function maintenanceWorkOrderTabs(): array
    {
        return [
            $this->tab('basic', 'Basic Information', 'البيانات الأساسية', [
                $this->section('document', 'Work Order Header', 'رأس أمر الصيانة', $this->documentHeaderFields('maintenance')),
            ]),
            $this->tab('asset', 'Asset / Machine / Mold', 'الأصل / الماكينة / الاسطمبة', [
                $this->section('asset', 'Asset Information', 'بيانات الأصل', [
                    $this->field('asset_doc_num', 'Asset', 'الأصل', 'empty_select2', 6, true),
                    $this->field('meter_reading', 'Meter Reading', 'قراءة العداد', 'decimal', 3),
                    $this->field('operating_hours', 'Operating Hours', 'ساعات التشغيل', 'decimal', 3),
                ]),
            ]),
            $this->tab('failure', 'Failure Details', 'تفاصيل العطل', [
                $this->section('failure', 'Failure Information', 'بيانات العطل', [
                    $this->field('failure_type', 'Failure Type', 'نوع العطل', 'empty_select2', 4),
                    $this->field('failure_cause', 'Failure Cause', 'سبب العطل', 'empty_select2', 4),
                    $this->field('failure_description', 'Failure Description', 'وصف العطل', 'textarea', 12),
                ]),
            ]),
            $this->tab('tasks', 'Tasks', 'المهام', [], [$this->detailRepeater('maintenance', 'maintenance_work_order')]),
            $this->tab('technicians', 'Technicians', 'الفنيون', [], [$this->simpleResultRepeater('technicians', 'Technicians', 'الفنيون')]),
            $this->tab('spare_parts', 'Spare Parts', 'قطع الغيار', [], [$this->simpleResultRepeater('spare_parts', 'Spare Parts', 'قطع الغيار')]),
            $this->tab('downtime', 'Downtime', 'التوقف', [], [$this->simpleResultRepeater('downtime', 'Downtime Entries', 'سجلات التوقف')]),
            $this->tab('costs', 'Costs', 'التكاليف', [
                $this->section('costs', 'UI-only Cost Fields', 'حقول التكلفة - واجهة فقط', [
                    $this->field('labor_cost', 'Labor Cost', 'تكلفة العمالة', 'readonly_calculated', 4),
                    $this->field('spare_parts_cost', 'Spare Parts Cost', 'تكلفة قطع الغيار', 'readonly_calculated', 4),
                    $this->field('total_cost', 'Total Cost', 'إجمالي التكلفة', 'readonly_calculated', 4),
                ]),
            ]),
            $this->attachmentsTab(),
            $this->historyTab(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reportTabs(): array
    {
        return [
            $this->tab('filters', 'Filters', 'عوامل التصفية', [
                $this->section('filters', 'Report Filters', 'عوامل تصفية التقرير', [
                    $this->field('date_from', 'From Date', 'من تاريخ', 'date', 3),
                    $this->field('date_to', 'To Date', 'إلى تاريخ', 'date', 3),
                    $this->field('company_doc_num', 'Company', 'الشركة', 'ajax_select2', 3, false, ['endpoint' => 'admin.select2.companies']),
                    $this->field('branch_doc_num', 'Branch', 'الفرع', 'ajax_select2', 3, false, ['endpoint' => 'admin.select2.branches']),
                    $this->field('status', 'Status', 'الحالة', 'multi_select', 3, false, ['options' => $this->statuses()]),
                    $this->field('search', 'Search', 'بحث', 'text', 6),
                ]),
            ]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function qualityCharacteristicFields(): array
    {
        return [
            $this->field('characteristic', 'Characteristic', 'الخاصية', 'empty_select2', 4, true),
            $this->field('standard_expected_result', 'Standard / Expected Result', 'المعيار / النتيجة المتوقعة', 'text', 4),
            $this->field('tolerance_minimum', 'Tolerance Minimum', 'الحد الأدنى للتفاوت', 'decimal', 2),
            $this->field('tolerance_maximum', 'Tolerance Maximum', 'الحد الأقصى للتفاوت', 'decimal', 2),
            $this->field('actual_result', 'Actual Result', 'النتيجة الفعلية', 'text', 3),
            $this->field('unit_doc_num', 'Unit', 'الوحدة', 'ajax_select2', 3, false, ['endpoint' => 'admin.select2.item-units']),
            $this->field('pass_fail', 'Pass / Fail', 'مطابق / غير مطابق', 'static_select', 3, false, ['options' => $this->passFailOptions()]),
            $this->field('notes', 'Notes', 'ملاحظات', 'textarea', 12),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function simpleResultRepeater(string $key, string $english, string $arabic): array
    {
        return $this->repeater($key, $english, $arabic, [
            $this->field('reference', 'Reference', 'المرجع', 'text', 4),
            $this->field('description', 'Description', 'الوصف', 'text', 4),
            $this->field('result', 'Result / Value', 'النتيجة / القيمة', 'text', 4),
            $this->field('notes', 'Notes', 'ملاحظات', 'textarea', 12),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attachmentsTab(): array
    {
        return $this->tab('attachments', 'Attachments', 'المرفقات', [
            $this->section('attachments', 'Document Attachments', 'مرفقات المستند', [
                $this->field('attachments', 'Attachments', 'المرفقات', 'attachment_list', 12),
                $this->field('attachment_picker', 'Add Attachment', 'إضافة مرفق', 'file_picker', 12, false, ['modes' => ['create', 'edit', 'clone']]),
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function historyTab(): array
    {
        return $this->tab('history', 'Audit / History', 'التدقيق / السجل', [
            $this->section('audit', 'Audit Information', 'بيانات التدقيق', [
                $this->field('created_by', 'Created By', 'أنشئ بواسطة', 'readonly_calculated', 3),
                $this->field('created_at', 'Created At', 'تاريخ الإنشاء', 'readonly_calculated', 3),
                $this->field('updated_by', 'Updated By', 'عدل بواسطة', 'readonly_calculated', 3),
                $this->field('updated_at', 'Updated At', 'تاريخ التعديل', 'readonly_calculated', 3),
                $this->field('history_notice', 'History', 'السجل', 'alert', 12),
            ]),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  list<array<string, mixed>>  $repeaters
     * @return array<string, mixed>
     */
    private function tab(string $key, string $english, string $arabic, array $sections = [], array $repeaters = []): array
    {
        return [
            'key' => $key,
            'title' => ['en' => $english, 'ar' => $arabic],
            'sections' => $sections,
            'repeaters' => $repeaters,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function section(string $key, string $english, string $arabic, array $fields): array
    {
        return [
            'key' => $key,
            'title' => ['en' => $english, 'ar' => $arabic],
            'fields' => $fields,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function field(string $name, string $english, string $arabic, string $type, int $width = 4, bool $required = false, array $extra = []): array
    {
        return [
            'name' => $name,
            'label' => ['en' => $english, 'ar' => $arabic],
            'type' => $type,
            'width' => $width,
            'required' => $required,
            'placeholder' => ['en' => 'Enter or select '.$english, 'ar' => 'أدخل أو اختر '.$arabic],
            ...$extra,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array<string, mixed>>  $nestedRepeaters
     * @return array<string, mixed>
     */
    private function repeater(string $key, string $english, string $arabic, array $fields, array $nestedRepeaters = []): array
    {
        return [
            'key' => $key,
            'title' => ['en' => $english, 'ar' => $arabic],
            'fields' => $fields,
            'nested_repeaters' => $nestedRepeaters,
            'initial_cards' => 1,
            'editable' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function column(string $data, string $english, string $arabic, bool $protected = false, bool $orderable = true): array
    {
        return [
            'data' => $data,
            'title' => ['en' => $english, 'ar' => $arabic],
            'protected' => $protected,
            'orderable' => $orderable,
            'searchable' => ! in_array($data, ['select', 'actions'], true),
        ];
    }

    /**
     * @return list<array{value: string, label: array{en: string, ar: string}}>
     */
    private function statuses(): array
    {
        return [
            ['value' => 'draft', 'label' => ['en' => 'Draft', 'ar' => 'مسودة']],
            ['value' => 'active', 'label' => ['en' => 'Active', 'ar' => 'نشط']],
            ['value' => 'under_review', 'label' => ['en' => 'Under Review', 'ar' => 'قيد المراجعة']],
            ['value' => 'approved', 'label' => ['en' => 'Approved', 'ar' => 'معتمد']],
            ['value' => 'closed', 'label' => ['en' => 'Closed', 'ar' => 'مغلق']],
            ['value' => 'cancelled', 'label' => ['en' => 'Cancelled', 'ar' => 'ملغي']],
        ];
    }

    /**
     * @return list<array{value: string, label: array{en: string, ar: string}}>
     */
    private function genericOptions(): array
    {
        return [
            ['value' => 'standard', 'label' => ['en' => 'Standard', 'ar' => 'قياسي']],
            ['value' => 'special', 'label' => ['en' => 'Special', 'ar' => 'خاص']],
        ];
    }

    /**
     * @return list<array{value: string, label: array{en: string, ar: string}}>
     */
    private function priorityOptions(): array
    {
        return [
            ['value' => 'low', 'label' => ['en' => 'Low', 'ar' => 'منخفضة']],
            ['value' => 'normal', 'label' => ['en' => 'Normal', 'ar' => 'عادية']],
            ['value' => 'high', 'label' => ['en' => 'High', 'ar' => 'مرتفعة']],
            ['value' => 'emergency', 'label' => ['en' => 'Emergency', 'ar' => 'طارئة']],
        ];
    }

    /**
     * @return list<array{value: string, label: array{en: string, ar: string}}>
     */
    private function passFailOptions(): array
    {
        return [
            ['value' => 'pass', 'label' => ['en' => 'Pass', 'ar' => 'مطابق']],
            ['value' => 'fail', 'label' => ['en' => 'Fail', 'ar' => 'غير مطابق']],
        ];
    }

    private function iconFor(string $profile): string
    {
        return match ($profile) {
            'master' => 'list-alt',
            'setup' => 'cog',
            'report', 'inquiry' => 'chart-bar',
            default => 'file-alt',
        };
    }
}
