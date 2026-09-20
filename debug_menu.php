<?php
// Debug: trace menu structure at different stages
require_once __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$menu = $app->make(\Modules\Core\Services\MenuService::class);

// Stage 1: structure (after organizeByDomain + finalizeSection)
$stage1 = $menu->structure();
echo "=== STAGE 1: structure() ===\n";
foreach ($stage1 as $s) {
    if (($s['label'] ?? '') === 'finance') {
        echo "Finance section:\n";
        echo "  children count: " . count($s['children'] ?? []) . "\n";
        echo "  subgroups count: " . count($s['subgroups'] ?? []) . "\n";
        foreach ($s['children'] ?? [] as $i => $ch) {
            $ccc = count($ch['children'] ?? []);
            echo "  [$i] {$ch['label']} => {$ch['title']} (children: $ccc)\n";
        }
        foreach ($s['subgroups'] ?? [] as $sg) {
            echo "  SUBGROUP: {$sg['label']} => {$sg['title']} (children: " . count($sg['children'] ?? []) . ")\n";
        }
    }
}

echo "\n=== STAGE 2: Raw domain items (before organizeByDomain) ===\n";
$domainItems = $menu->menuConfigItems();
$erpUiItems = $menu->erpUiScreens->menuItems();
echo "menuConfigItems: " . count($domainItems) . " items\n";
echo "erpUiScreens menuItems: " . count($erpUiItems) . " items\n";

// Find the reports_finance group
foreach ($erpUiItems as $item) {
    if (($item['label'] ?? '') === 'reports') {
        echo "Reports module children:\n";
        foreach ($item['children'] ?? [] as $child) {
            echo "  - {$child['label']} => {$child['title']} (children: " . count($child['children'] ?? []) . ")\n";
        }
    }
}
