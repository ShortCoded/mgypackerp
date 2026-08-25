<?php

$navigationHiddenKeys = [
    'product_data_product_units',
    'product_data_product_equivalent_units',
    'product_data_product_barcodes',
    'product_data_product_images',
    'product_data_product_documents',
];

$screen = static function (string $slug, string $en, string $ar, string $group = 'classification', string $profile = 'master', array $extra = []) use ($navigationHiddenKeys): array {
    $key = 'product_data_'.str_replace('-', '_', $slug);

    return [
        'key' => $key,
        'slug' => $slug,
        'title' => ['en' => $en, 'ar' => $ar],
        'group' => $group,
        'profile' => $profile,
        'menu_visible' => ! in_array($key, $navigationHiddenKeys, true),
        ...$extra,
    ];
};

return [
    'module' => 'product_data',
    'title' => ['en' => 'Product and Material Data', 'ar' => 'بيانات المنتجات والخامات'],
    'route_segment' => 'product-data',
    'route_name' => 'product-data',
    'permission_prefix' => 'product_data',
    'menu' => ['label' => 'item_data', 'title' => ['en' => 'Product and Material Data', 'ar' => 'بيانات المنتجات والخامات'], 'icon' => 'boxes', 'order' => 30],
    'groups' => [
        'classification' => ['title' => ['en' => 'Types and Classification', 'ar' => 'الأنواع والتصنيف'], 'icon' => 'layer-group', 'order' => 10],
        'technical' => ['title' => ['en' => 'Technical Definitions', 'ar' => 'التعريفات الفنية'], 'icon' => 'drafting-compass', 'order' => 20],
        'logistics' => ['title' => ['en' => 'Packaging and Storage', 'ar' => 'التعبئة والتخزين'], 'icon' => 'box-open', 'order' => 30],
        'planning' => ['title' => ['en' => 'Planning Policies', 'ar' => 'سياسات التخطيط'], 'icon' => 'clipboard-list', 'order' => 40],
        'relationships' => ['title' => ['en' => 'Manufacturing Relationships', 'ar' => 'علاقات التصنيع'], 'icon' => 'project-diagram', 'order' => 50],
    ],
    'screens' => [
        $screen('product-types', 'Product Types', 'أنواع المنتجات'),
        $screen('raw-material-types', 'Raw Material Types', 'أنواع الخامات'),
        $screen('semi-finished-product-types', 'Semi-Finished Product Types', 'أنواع المنتجات نصف المصنعة'),
        $screen('finished-product-types', 'Finished Product Types', 'أنواع المنتجات التامة'),
        $screen('packaging-material-types', 'Packaging Material Types', 'أنواع مواد التعبئة'),
        $screen('service-types', 'Service Types', 'أنواع الخدمات'),
        $screen('product-families', 'Product Families', 'عائلات المنتجات'),
        $screen('product-brands', 'Product Brands', 'العلامات التجارية للمنتجات'),
        $screen('product-grades', 'Product Grades', 'درجات المنتجات'),
        $screen('product-specifications', 'Product Specifications', 'مواصفات المنتجات', 'technical', 'document'),
        $screen('product-technical-properties', 'Product Technical Properties', 'الخصائص الفنية للمنتجات', 'technical', 'document'),
        $screen('product-units', 'Product Units', 'وحدات المنتجات', 'technical', 'document'),
        $screen('product-equivalent-units', 'Product Equivalent Units', 'الوحدات المكافئة للمنتجات', 'technical', 'document'),
        $screen('product-barcodes', 'Product Barcodes', 'باركود المنتجات', 'technical', 'document'),
        $screen('product-images', 'Product Images', 'صور المنتجات', 'technical', 'document'),
        $screen('product-documents', 'Product Documents', 'مستندات المنتجات', 'technical', 'document'),
        $screen('product-bom', 'Product BOM', 'قائمة مكونات المنتج', 'technical', 'document'),
        $screen('product-routing', 'Product Routing', 'مسار تشغيل المنتج', 'technical', 'document'),
        $screen('product-packaging-definitions', 'Product Packaging Definitions', 'تعريفات تعبئة المنتج', 'logistics', 'document'),
        $screen('product-storage-requirements', 'Product Storage Requirements', 'متطلبات تخزين المنتج', 'logistics', 'document'),
        $screen('product-quality-specifications', 'Product Quality Specifications', 'مواصفات جودة المنتج', 'technical', 'document'),
        $screen('product-reorder-policies', 'Product Reorder Policies', 'سياسات إعادة طلب المنتج', 'planning', 'document'),
        $screen('product-safety-stock-policies', 'Product Safety Stock Policies', 'سياسات مخزون الأمان للمنتج', 'planning', 'document'),
        $screen('product-batch-policies', 'Product Batch Policies', 'سياسات تشغيلات المنتج', 'planning', 'document'),
        $screen('product-shelf-life-policies', 'Product Shelf-Life Policies', 'سياسات صلاحية المنتج', 'planning', 'document'),
        $screen('mold-product-relationships', 'Mold / Product Relationships', 'علاقات الاسطمبة والمنتج', 'relationships', 'document'),
        $screen('machine-product-relationships', 'Machine / Product Relationships', 'علاقات الماكينة والمنتج', 'relationships', 'document'),
        $screen('raw-material-substitution-rules', 'Raw Material Substitution Rules', 'قواعد استبدال الخامات', 'relationships', 'document'),
        $screen('scrap-definitions', 'Scrap Definitions', 'تعريفات الهالك', 'relationships'),
        $screen('waste-definitions', 'Waste Definitions', 'تعريفات الفاقد', 'relationships'),
    ],
];
