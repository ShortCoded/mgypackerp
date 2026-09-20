<?php
// Extract all translation keys from PHP files

$arKeys = [];
$enKeys = [];

// Get keys from ar/*.php
foreach (glob('resources/lang/ar/*.php') as $file) {
    $data = include $file;
    if (is_array($data)) {
        foreach (array_keys($data) as $key) {
            $arKeys[$key] = true;
        }
    }
}

// Get keys from en/*.php
foreach (glob('resources/lang/en/*.php') as $file) {
    $data = include $file;
    if (is_array($data)) {
        foreach (array_keys($data) as $key) {
            $enKeys[$key] = true;
        }
    }
}

// Get keys from JSON files
$arJson = json_decode(file_get_contents('resources/lang/ar.json'), true) ?? [];
$enJson = json_decode(file_get_contents('resources/lang/en.json'), true) ?? [];

$arJsonKeys = array_keys($arJson);
$enJsonKeys = array_keys($enJson);

echo "=== AR PHP Keys: " . count($arKeys) . "\n";
echo "=== EN PHP Keys: " . count($enKeys) . "\n";
echo "=== AR JSON Keys: " . count($arJsonKeys) . "\n";
echo "=== EN JSON Keys: " . count($enJsonKeys) . "\n";

// Save to files
file_put_contents('/tmp/ar_keys.txt', implode("\n", array_keys($arKeys)) . "\n");
file_put_contents('/tmp/en_keys.txt', implode("\n", array_keys($enKeys)) . "\n");
file_put_contents('/tmp/ar_json_keys.txt', implode("\n", $arJsonKeys) . "\n");
file_put_contents('/tmp/en_json_keys.txt', implode("\n", $enJsonKeys) . "\n");

// Find differences
$onlyInAr = array_diff(array_keys($arKeys), array_keys($enKeys));
$onlyInEn = array_diff(array_keys($enKeys), array_keys($arKeys));

echo "\n=== Keys only in AR (not EN): " . count($onlyInAr) . "\n";
foreach ($onlyInAr as $k) echo "  AR ONLY: $k\n";

echo "\n=== Keys only in EN (not AR): " . count($onlyInEn) . "\n";
foreach ($onlyInEn as $k) echo "  EN ONLY: $k\n";

// Combined keys (PHP + JSON)
$allAr = array_merge(array_keys($arKeys), $arJsonKeys);
$allEn = array_merge(array_keys($enKeys), $enJsonKeys);

$arMissingEn = array_diff($allAr, $allEn);
$enMissingAr = array_diff($allEn, $allAr);

echo "\n=== All AR keys missing in EN: " . count($arMissingEn) . "\n";
foreach ($arMissingEn as $k) echo "  $k\n";

echo "\n=== All EN keys missing in AR: " . count($enMissingAr) . "\n";
foreach ($enMissingAr as $k) echo "  $k\n";

// Empty translations
$emptyAr = [];
foreach ($arKeys as $k => $_) {
    if (empty($arKeys[$k])) $emptyAr[] = $k;
}
foreach ($arJson as $k => $v) {
    if (empty($v)) $emptyAr[] = $k;
}

$emptyEn = [];
foreach ($enKeys as $k => $_) {
    if (empty($enKeys[$k])) $emptyEn[] = $k;
}
foreach ($enJson as $k => $v) {
    if (empty($v)) $emptyEn[] = $k;
}

echo "\n=== Empty AR translations: " . count($emptyAr) . "\n";
echo "\n=== Empty EN translations: " . count($emptyEn) . "\n";
