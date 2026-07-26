<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\ProductBomService;
use Modules\Core\Services\ProductComponentService;
use Modules\Core\Services\ProductComponentUnitConversionService;
use Modules\Core\Services\ProductService;

function productBomWeightOperatingContext(object $test): Company
{
    static $documentNumber = 9400;

    $documentNumber++;
    $company = Company::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'BOM Weight Company '.$documentNumber,
        'status' => 'active',
        'is_main' => true,
    ]);
    $branch = Branch::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Branch-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'BOM Weight Branch '.$documentNumber,
        'type' => Branch::TypeFactory,
        'status' => 'active',
    ]);
    $period = FinancialPeriod::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => 'Period-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'company_id' => $company->getKey(),
        'name' => 'BOM Weight Period '.$documentNumber,
        'from_date' => '2026-01-01',
        'to_date' => '2026-12-31',
        'is_closed' => false,
    ]);

    $session = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    $test->withSession($session);
    session()->put($session);
    request()->setLaravelSession(session()->driver());

    return $company;
}

function productBomWeightUnit(Company $company, string $name, array $overrides = []): ItemUnit
{
    static $documentNumber = 9500;

    $documentNumber++;

    return ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $documentNumber,
        'doc_num' => 'Unit-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => $name,
        'status' => 'active',
        ...$overrides,
    ]);
}

function productBomWeightProduct(Company $company, array $overrides = []): Product
{
    static $documentNumber = 9600;

    $documentNumber++;

    return Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => $documentNumber,
        'doc_num' => 'Product-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
        'name' => 'BOM Weight Product '.$documentNumber,
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
        ...$overrides,
    ]);
}

function productBomWeightMaterial(
    Company $company,
    ItemUnit $unit,
    array $overrides = [],
): Product {
    return productBomWeightProduct($company, [
        'item_classification' => Product::ClassificationRawMaterial,
        'item_unit_id' => $unit->getKey(),
        ...$overrides,
    ]);
}

/**
 * @return array<string, mixed>
 */
function productBomWeightDirectRow(
    Product $material,
    ItemUnit $unit,
    string $quantity,
    ?string $clientKey = null,
    array $overrides = [],
): array {
    return [
        'client_key' => $clientKey ?? (string) Str::uuid(),
        'public_id' => null,
        'component_product_id' => $material->getKey(),
        'unit_id' => $unit->getKey(),
        'calculation_method' => ProductComponent::CalculationDirect,
        'quantity' => $quantity,
        'percentage' => null,
        'reference_component_key' => null,
        'input_source' => ProductComponent::InputWeight,
        'notes' => null,
        '_delete' => false,
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function productBomWeightPercentageRow(
    Product $material,
    ItemUnit $unit,
    string $referenceKey,
    ?string $percentage,
    ?string $quantity = null,
    string $inputSource = ProductComponent::InputPercentage,
    ?string $clientKey = null,
    array $overrides = [],
): array {
    return [
        'client_key' => $clientKey ?? (string) Str::uuid(),
        'public_id' => null,
        'component_product_id' => $material->getKey(),
        'unit_id' => $unit->getKey(),
        'calculation_method' => ProductComponent::CalculationPercentage,
        'quantity' => $quantity,
        'percentage' => $percentage,
        'reference_component_key' => $referenceKey,
        'input_source' => $inputSource,
        'notes' => null,
        '_delete' => false,
        ...$overrides,
    ];
}

/**
 * @return array<string, list<string>>
 */
function productBomWeightValidationErrors(callable $operation): array
{
    try {
        $operation();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Expected BOM validation to fail.');
}

beforeEach(function (): void {
    $this->bomWeightCompany = productBomWeightOperatingContext($this);
    $this->bomWeightService = app(ProductBomService::class);
    $this->bomWeightActor = User::factory()->create();
    $this->actingAs($this->bomWeightActor);
    request()->setUserResolver(fn (): User => $this->bomWeightActor);
});

test('fresh schema defaults preserve legacy direct rows and inventory cost choices', function () {
    expect(Schema::hasColumns('product_components', [
        'calculation_method',
        'percentage',
        'reference_component_id',
    ]))->toBeTrue();

    $unit = productBomWeightUnit($this->bomWeightCompany, 'Kilogram');
    $product = productBomWeightProduct($this->bomWeightCompany);
    $material = productBomWeightMaterial($this->bomWeightCompany, $unit);

    $legacyComponentId = DB::table('product_components')->insertGetId([
        'public_id' => (string) Str::uuid(),
        'company_id' => $this->bomWeightCompany->getKey(),
        'product_id' => $product->getKey(),
        'component_product_id' => $material->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '7.125',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $legacyComponent = ProductComponent::query()->findOrFail($legacyComponentId);

    expect($legacyComponent->calculation_method)->toBe(ProductComponent::CalculationDirect)
        ->and((string) $legacyComponent->quantity)->toBe('7.12500000')
        ->and($legacyComponent->percentage)->toBeNull()
        ->and($legacyComponent->reference_component_id)->toBeNull();

    $unitlessMaterial = productBomWeightProduct($this->bomWeightCompany, [
        'item_classification' => Product::ClassificationRawMaterial,
    ]);
    DB::table('product_components')->insert([
        'public_id' => (string) Str::uuid(),
        'company_id' => $this->bomWeightCompany->getKey(),
        'product_id' => $product->getKey(),
        'component_product_id' => $unitlessMaterial->getKey(),
        'unit_id' => null,
        'quantity' => '3.5',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $legacyClone = productBomWeightProduct($this->bomWeightCompany, [
        'name' => 'Legacy unitless BOM clone',
    ]);

    $this->bomWeightService->clone($legacyClone, $product);

    $clonedUnitless = ProductComponent::query()
        ->where('product_id', $legacyClone->getKey())
        ->where('component_product_id', $unitlessMaterial->getKey())
        ->firstOrFail();

    expect($clonedUnitless->calculation_method)->toBe(ProductComponent::CalculationDirect)
        ->and($clonedUnitless->unit_id)->toBeNull()
        ->and((string) $clonedUnitless->quantity)->toBe('3.50000000');

    $modelDefault = productBomWeightProduct($this->bomWeightCompany, [
        'name' => 'Model default inventory cost',
    ]);
    $modelExplicitFalse = productBomWeightProduct($this->bomWeightCompany, [
        'name' => 'Model explicit false inventory cost',
        'cost_as_inventory' => false,
    ]);
    $queryDefaultId = DB::table('products')->insertGetId([
        'company_id' => $this->bomWeightCompany->getKey(),
        'name' => 'Database default inventory cost',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($modelDefault->cost_as_inventory)->toBeTrue()
        ->and($modelExplicitFalse->cost_as_inventory)->toBeFalse()
        ->and((bool) DB::table('products')->where('id', $queryDefaultId)->value('cost_as_inventory'))->toBeTrue();

    $actor = User::factory()->create();
    $this->actingAs($actor);
    request()->setUserResolver(fn (): User => $actor);
    $serviceDefault = app(ProductService::class)->create([
        'name' => 'Service default inventory cost',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ])['record'];
    $serviceExplicitFalse = app(ProductService::class)->create([
        'name' => 'Service explicit false inventory cost',
        'item_classification' => Product::ClassificationFinishedProduct,
        'cost_as_inventory' => false,
        'status' => 'active',
    ])['record'];
    $serviceClone = app(ProductService::class)->create([
        'name' => 'Service clone inventory cost',
        'item_classification' => Product::ClassificationFinishedProduct,
        'status' => 'active',
    ], $modelExplicitFalse)['record'];

    expect($serviceDefault->cost_as_inventory)->toBeTrue()
        ->and($serviceExplicitFalse->cost_as_inventory)->toBeFalse()
        ->and($serviceClone->cost_as_inventory)->toBeFalse()
        ->and($modelExplicitFalse->refresh()->cost_as_inventory)->toBeFalse();
});

test('percentage input is authoritative and accepts values above one hundred percent', function () {
    $unit = productBomWeightUnit($this->bomWeightCompany, 'Kilogram');
    $product = productBomWeightProduct($this->bomWeightCompany);
    $baseMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $twoPercentMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $overHundredMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $baseKey = (string) Str::uuid();

    $components = $this->bomWeightService->sync($product, [
        productBomWeightDirectRow($baseMaterial, $unit, '100', $baseKey, [
            'percentage' => '88',
            'reference_component_key' => (string) Str::uuid(),
        ]),
        productBomWeightPercentageRow(
            $twoPercentMaterial,
            $unit,
            $baseKey,
            '2',
            '999',
            ProductComponent::InputPercentage,
        ),
        productBomWeightPercentageRow(
            $overHundredMaterial,
            $unit,
            $baseKey,
            '125',
            '1',
            ProductComponent::InputPercentage,
        ),
    ]);

    [$base, $twoPercent, $overHundred] = $components;

    expect((string) $base->quantity)->toBe('100.00000000')
        ->and($base->percentage)->toBeNull()
        ->and($base->reference_component_id)->toBeNull()
        ->and((string) $twoPercent->quantity)->toBe('2.00000000')
        ->and((string) $twoPercent->percentage)->toBe('2.00000000')
        ->and($twoPercent->reference_component_id)->toBe($base->getKey())
        ->and($twoPercent->updated_by)->toBeNull()
        ->and($twoPercent->updated_at)->toBeNull()
        ->and((string) $overHundred->quantity)->toBe('125.00000000')
        ->and((string) $overHundred->percentage)->toBe('125.00000000');
});

test('weight input becomes the durable percentage and base changes recalculate it', function () {
    $unit = productBomWeightUnit($this->bomWeightCompany, 'Kilogram');
    $product = productBomWeightProduct($this->bomWeightCompany);
    $baseMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $dependentMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $baseKey = (string) Str::uuid();

    [$base, $dependent] = $this->bomWeightService->sync($product, [
        productBomWeightDirectRow($baseMaterial, $unit, '100', $baseKey),
        productBomWeightPercentageRow(
            $dependentMaterial,
            $unit,
            $baseKey,
            '77',
            '10',
            ProductComponent::InputWeight,
        ),
    ]);

    expect((string) $dependent->quantity)->toBe('10.00000000')
        ->and((string) $dependent->percentage)->toBe('10.00000000');

    $payload = collect($this->bomWeightService->currentPayload($product))
        ->map(function (array $row) use ($base, $dependent): array {
            if ($row['public_id'] === $base->public_id) {
                $row['quantity'] = '200';
            }

            if ($row['public_id'] === $dependent->public_id) {
                $row['quantity'] = '999';
            }

            return $row;
        })
        ->all();

    $this->bomWeightService->sync($product, $payload);

    expect((string) $base->refresh()->quantity)->toBe('200.00000000')
        ->and((string) $dependent->refresh()->quantity)->toBe('20.00000000')
        ->and((string) $dependent->percentage)->toBe('10.00000000');

    $staleDerivedPayload = collect($this->bomWeightService->currentPayload($product))
        ->map(function (array $row) use ($dependent): array {
            if ($row['public_id'] === $dependent->public_id) {
                $row['quantity'] = '999';
            }

            return $row;
        })
        ->all();
    $update = app(ProductService::class)->update($product, [
        'components' => $staleDerivedPayload,
    ]);

    expect($update['changed'])->toBeFalse()
        ->and((string) $dependent->refresh()->quantity)->toBe('20.00000000');
});

test('weight input derives percentage with one deterministic half-up rounding step', function () {
    $unit = productBomWeightUnit($this->bomWeightCompany, 'Kilogram');
    $product = productBomWeightProduct($this->bomWeightCompany);
    $baseMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $dependentMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $baseKey = (string) Str::uuid();

    [, $dependent] = $this->bomWeightService->sync($product, [
        productBomWeightDirectRow($baseMaterial, $unit, '8681.10753694', $baseKey),
        productBomWeightPercentageRow(
            $dependentMaterial,
            $unit,
            $baseKey,
            null,
            '8097.84592076',
            ProductComponent::InputWeight,
        ),
    ]);

    expect((string) $dependent->percentage)->toBe('93.28125341')
        ->and((string) $dependent->quantity)->toBe('8097.84592033');

    $javascript = file_get_contents(public_path('assets/js/modules/Core/products.js'));

    expect($javascript)
        ->toContain("var percentageNumerator = decimalMultiply(\n                    weight,\n                    '100',")
        ->toContain("decimalDivide(\n                    percentageNumerator,\n                    convertedReferenceWeight,\n                    componentCalculationScale");
});

test('new forward uuid references and dependency chains resolve in graph order', function () {
    $unit = productBomWeightUnit($this->bomWeightCompany, 'Kilogram');
    $product = productBomWeightProduct($this->bomWeightCompany);
    $baseMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $middleMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $lastMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $baseKey = (string) Str::uuid();
    $middleKey = (string) Str::uuid();
    $lastKey = (string) Str::uuid();

    [$last, $middle, $base] = $this->bomWeightService->sync($product, [
        productBomWeightPercentageRow($lastMaterial, $unit, $middleKey, '50', null, ProductComponent::InputPercentage, $lastKey),
        productBomWeightPercentageRow($middleMaterial, $unit, $baseKey, '2', null, ProductComponent::InputPercentage, $middleKey),
        productBomWeightDirectRow($baseMaterial, $unit, '100', $baseKey),
    ]);

    expect((string) $base->quantity)->toBe('100.00000000')
        ->and((string) $middle->quantity)->toBe('2.00000000')
        ->and((string) $last->quantity)->toBe('1.00000000')
        ->and($middle->reference_component_id)->toBe($base->getKey())
        ->and($last->reference_component_id)->toBe($middle->getKey());

    $reordered = collect($this->bomWeightService->currentPayload($product))
        ->sortBy(fn (array $row): int => match ($row['public_id']) {
            $base->public_id => 1,
            $last->public_id => 2,
            default => 3,
        })
        ->values()
        ->all();

    $this->bomWeightService->sync($product, $reordered);

    expect($middle->refresh()->reference_component_id)->toBe($base->getKey())
        ->and($last->refresh()->reference_component_id)->toBe($middle->getKey());
});

test('self cycle orphan and cross product references are rejected', function () {
    $unit = productBomWeightUnit($this->bomWeightCompany, 'Kilogram');
    $product = productBomWeightProduct($this->bomWeightCompany);
    $materialA = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $materialB = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $materialC = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $keyA = (string) Str::uuid();
    $keyB = (string) Str::uuid();
    $keyC = (string) Str::uuid();

    $selfErrors = productBomWeightValidationErrors(fn () => $this->bomWeightService->sync($product, [
        productBomWeightPercentageRow($materialA, $unit, $keyA, '2', null, ProductComponent::InputPercentage, $keyA),
    ]));

    expect($selfErrors)->toHaveKey('components.0.reference_component_key');

    $orphanErrors = productBomWeightValidationErrors(fn () => $this->bomWeightService->sync($product, [
        productBomWeightPercentageRow($materialA, $unit, (string) Str::uuid(), '2'),
    ]));

    expect($orphanErrors)->toHaveKey('components.0.reference_component_key');

    $cycleErrors = productBomWeightValidationErrors(fn () => $this->bomWeightService->sync($product, [
        productBomWeightPercentageRow($materialA, $unit, $keyB, '2', null, ProductComponent::InputPercentage, $keyA),
        productBomWeightPercentageRow($materialB, $unit, $keyA, '2', null, ProductComponent::InputPercentage, $keyB),
    ]));

    expect($cycleErrors)
        ->toHaveKey('components.0.reference_component_key')
        ->toHaveKey('components.1.reference_component_key');

    $longCycleErrors = productBomWeightValidationErrors(fn () => $this->bomWeightService->sync($product, [
        productBomWeightPercentageRow($materialA, $unit, $keyB, '2', null, ProductComponent::InputPercentage, $keyA),
        productBomWeightPercentageRow($materialB, $unit, $keyC, '2', null, ProductComponent::InputPercentage, $keyB),
        productBomWeightPercentageRow($materialC, $unit, $keyA, '2', null, ProductComponent::InputPercentage, $keyC),
    ]));

    expect($longCycleErrors)
        ->toHaveKey('components.0.reference_component_key')
        ->toHaveKey('components.1.reference_component_key')
        ->toHaveKey('components.2.reference_component_key');

    $otherProduct = productBomWeightProduct($this->bomWeightCompany);
    [$foreignComponent] = $this->bomWeightService->sync($otherProduct, [
        productBomWeightDirectRow($materialA, $unit, '100'),
    ]);
    $localBaseKey = (string) Str::uuid();
    $crossProductErrors = productBomWeightValidationErrors(fn () => $this->bomWeightService->sync($product, [
        productBomWeightDirectRow($materialA, $unit, '100', $localBaseKey),
        productBomWeightPercentageRow($materialB, $unit, (string) $foreignComponent->public_id, '2'),
    ]));

    expect($crossProductErrors)->toHaveKey('components.1.reference_component_key')
        ->and(ProductComponent::query()->where('product_id', $product->getKey())->exists())->toBeFalse();
});

test('deleting a referenced row is blocked and the graph remains intact', function () {
    $unit = productBomWeightUnit($this->bomWeightCompany, 'Kilogram');
    $product = productBomWeightProduct($this->bomWeightCompany);
    $baseMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $dependentMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $baseKey = (string) Str::uuid();

    [$base, $dependent] = $this->bomWeightService->sync($product, [
        productBomWeightDirectRow($baseMaterial, $unit, '100', $baseKey),
        productBomWeightPercentageRow($dependentMaterial, $unit, $baseKey, '2'),
    ]);
    $payload = collect($this->bomWeightService->currentPayload($product))
        ->map(function (array $row) use ($base): array {
            if ($row['public_id'] === $base->public_id) {
                $row['_delete'] = true;
            }

            return $row;
        })
        ->all();

    $errors = productBomWeightValidationErrors(
        fn () => $this->bomWeightService->sync($product, $payload),
    );

    expect($errors)
        ->toHaveKey('components.0._delete')
        ->toHaveKey('components.1.reference_component_key')
        ->and(ProductComponent::query()->whereKey($base->getKey())->exists())->toBeTrue()
        ->and(ProductComponent::query()->whereKey($dependent->getKey())->exists())->toBeTrue()
        ->and($dependent->refresh()->reference_component_id)->toBe($base->getKey())
        ->and((string) $dependent->quantity)->toBe('2.00000000');
});

test('duplicate component items remain distinct bom line identities', function () {
    $unit = productBomWeightUnit($this->bomWeightCompany, 'Kilogram');
    $product = productBomWeightProduct($this->bomWeightCompany);
    $material = productBomWeightMaterial($this->bomWeightCompany, $unit);

    [$first, $second] = $this->bomWeightService->sync($product, [
        productBomWeightDirectRow($material, $unit, '1'),
        productBomWeightDirectRow($material, $unit, '2'),
    ]);

    expect($first->getKey())->not->toBe($second->getKey())
        ->and($first->public_id)->not->toBe($second->public_id)
        ->and($first->component_product_id)->toBe($second->component_product_id)
        ->and(ProductComponent::query()->where('product_id', $product->getKey())->count())->toBe(2);

    $payload = $this->bomWeightService->currentPayload($product);
    $duplicatePersistedLine = [
        ...$payload[0],
        'client_key' => (string) Str::uuid(),
    ];
    $errors = productBomWeightValidationErrors(fn () => $this->bomWeightService->sync($product, [
        $payload[0],
        $duplicatePersistedLine,
        $payload[1],
    ]));

    expect($errors)->toHaveKey('components.1.public_id')
        ->and(ProductComponent::query()->where('product_id', $product->getKey())->count())->toBe(2);
});

test('bom migration rollback refuses to delete or merge legitimate active duplicate lines', function () {
    $unit = productBomWeightUnit($this->bomWeightCompany, 'Kilogram');
    $product = productBomWeightProduct($this->bomWeightCompany);
    $material = productBomWeightMaterial($this->bomWeightCompany, $unit);

    $this->bomWeightService->sync($product, [
        productBomWeightDirectRow($material, $unit, '1'),
        productBomWeightDirectRow($material, $unit, '2'),
    ]);

    $migration = require base_path(
        'modules/Core/Database/Migrations/2026_07_26_151738_add_calculation_fields_to_product_components_table.php',
    );

    expect(fn () => $migration->down())
        ->toThrow(
            RuntimeException::class,
            'Cannot roll back the Product BOM calculation migration while duplicate active component items exist.',
        )
        ->and(Schema::hasColumn('product_components', 'calculation_method'))->toBeTrue()
        ->and(Schema::hasColumn('product_components', 'percentage'))->toBeTrue()
        ->and(Schema::hasColumn('product_components', 'reference_component_id'))->toBeTrue()
        ->and(ProductComponent::query()->where('product_id', $product->getKey())->count())->toBe(2);
});

test('same units and configured kilogram gram conversion resolve safely', function () {
    $gram = productBomWeightUnit($this->bomWeightCompany, 'Gram');
    $kilogram = productBomWeightUnit($this->bomWeightCompany, 'Kilogram', [
        'equivalent_value' => '1000',
        'equivalent_unit_id' => $gram->getKey(),
    ]);
    $piece = productBomWeightUnit($this->bomWeightCompany, 'Piece');
    $product = productBomWeightProduct($this->bomWeightCompany);
    $baseMaterial = productBomWeightMaterial($this->bomWeightCompany, $kilogram);
    $sameUnitMaterial = productBomWeightMaterial($this->bomWeightCompany, $kilogram);
    $gramMaterial = productBomWeightMaterial($this->bomWeightCompany, $gram);
    $pieceMaterial = productBomWeightMaterial($this->bomWeightCompany, $piece);
    $baseKey = (string) Str::uuid();

    [$base, $sameUnit, $converted] = $this->bomWeightService->sync($product, [
        productBomWeightDirectRow($baseMaterial, $kilogram, '100', $baseKey),
        productBomWeightPercentageRow($sameUnitMaterial, $kilogram, $baseKey, '2'),
        productBomWeightPercentageRow($gramMaterial, $gram, $baseKey, '2'),
    ]);

    expect((string) $base->quantity)->toBe('100.00000000')
        ->and((string) $sameUnit->quantity)->toBe('2.00000000')
        ->and((string) $converted->quantity)->toBe('2000.00000000');

    $incompatibleProduct = productBomWeightProduct($this->bomWeightCompany);
    $incompatibleBaseKey = (string) Str::uuid();
    $errors = productBomWeightValidationErrors(fn () => $this->bomWeightService->sync($incompatibleProduct, [
        productBomWeightDirectRow($baseMaterial, $kilogram, '100', $incompatibleBaseKey),
        productBomWeightPercentageRow($pieceMaterial, $piece, $incompatibleBaseKey, '2'),
    ]));

    expect($errors)->toHaveKey('components.1.reference_component_key');

    [$standaloneBase] = $this->bomWeightService->sync($incompatibleProduct, [
        productBomWeightDirectRow($baseMaterial, $kilogram, '100'),
    ]);
    $standaloneErrors = productBomWeightValidationErrors(
        fn () => app(ProductComponentService::class)->create($incompatibleProduct, [
            'component_product_id' => $pieceMaterial->getKey(),
            'unit_id' => $piece->getKey(),
            'calculation_method' => ProductComponent::CalculationPercentage,
            'percentage' => '2',
            'reference_component_key' => (string) $standaloneBase->public_id,
            'input_source' => ProductComponent::InputPercentage,
        ]),
    );

    expect($standaloneErrors)->toHaveKey('reference_component_key')
        ->not->toHaveKey('components.1.reference_component_key');
});

test('unit conversion rejects path conflicts that can change stored weight precision', function () {
    $dependentUnit = productBomWeightUnit($this->bomWeightCompany, 'Dependent Unit');
    $thirdPathUnit = productBomWeightUnit($this->bomWeightCompany, 'Third Path Unit', [
        'equivalent_value' => '1.067049',
        'equivalent_unit_id' => $dependentUnit->getKey(),
    ]);
    $secondPathUnit = productBomWeightUnit($this->bomWeightCompany, 'Second Path Unit', [
        'equivalent_value' => '1.351986',
        'equivalent_unit_id' => $thirdPathUnit->getKey(),
    ]);
    $referenceUnit = productBomWeightUnit($this->bomWeightCompany, 'Reference Unit', [
        'equivalent_value' => '0.898035',
        'equivalent_unit_id' => $secondPathUnit->getKey(),
    ]);
    $product = productBomWeightProduct($this->bomWeightCompany);
    $referenceMaterial = productBomWeightMaterial($this->bomWeightCompany, $referenceUnit, [
        'equivalent_value' => '1.295537',
        'equivalent_unit_id' => $dependentUnit->getKey(),
    ]);
    $dependentMaterial = productBomWeightMaterial($this->bomWeightCompany, $dependentUnit);
    $referenceKey = (string) Str::uuid();

    $errors = productBomWeightValidationErrors(fn () => $this->bomWeightService->sync($product, [
        productBomWeightDirectRow($referenceMaterial, $referenceUnit, '100', $referenceKey),
        productBomWeightPercentageRow($dependentMaterial, $dependentUnit, $referenceKey, '2'),
    ]));

    $largeFactorDependentUnit = productBomWeightUnit($this->bomWeightCompany, 'Large Factor Dependent Unit');
    $largeFactorReferenceUnit = productBomWeightUnit($this->bomWeightCompany, 'Large Factor Reference Unit', [
        'equivalent_value' => '999999999999.123456',
        'equivalent_unit_id' => $largeFactorDependentUnit->getKey(),
    ]);
    $largeFactorProduct = productBomWeightProduct($this->bomWeightCompany);
    $largeFactorReferenceMaterial = productBomWeightMaterial($this->bomWeightCompany, $largeFactorReferenceUnit);
    $largeFactorDependentMaterial = productBomWeightMaterial($this->bomWeightCompany, $largeFactorDependentUnit);
    $largeFactorReferenceKey = (string) Str::uuid();
    [, $largeFactorDependent] = $this->bomWeightService->sync($largeFactorProduct, [
        productBomWeightDirectRow($largeFactorReferenceMaterial, $largeFactorReferenceUnit, '0.00000001', $largeFactorReferenceKey),
        productBomWeightPercentageRow($largeFactorDependentMaterial, $largeFactorDependentUnit, $largeFactorReferenceKey, '2'),
    ]);

    expect($errors)->toHaveKey('components.1.reference_component_key')
        ->and((string) $largeFactorDependent->quantity)->toBe('200.00000000');
});

test('cross company and deleted unit equivalences are never inferred by reused document codes', function () {
    $localGram = productBomWeightUnit($this->bomWeightCompany, 'Local Gram');
    $foreignCompany = Company::query()->create([
        'doc_number' => 99991,
        'doc_num' => 'Company-99991',
        'name' => 'Foreign Unit Company',
        'status' => 'active',
        'is_main' => false,
    ]);
    $foreignGram = ItemUnit::query()->create([
        'company_id' => $foreignCompany->getKey(),
        'doc_number' => 1,
        'doc_num' => $localGram->doc_num,
        'name' => 'Foreign Gram With Reused Code',
        'status' => 'active',
    ]);
    $unsafeKilogram = productBomWeightUnit($this->bomWeightCompany, 'Unsafe Kilogram', [
        'equivalent_value' => '1000',
        'equivalent_unit_id' => $foreignGram->getKey(),
    ]);
    $product = productBomWeightProduct($this->bomWeightCompany);
    $baseMaterial = productBomWeightMaterial($this->bomWeightCompany, $unsafeKilogram);
    $dependentMaterial = productBomWeightMaterial($this->bomWeightCompany, $localGram);
    $baseKey = (string) Str::uuid();

    $errors = productBomWeightValidationErrors(fn () => $this->bomWeightService->sync($product, [
        productBomWeightDirectRow($baseMaterial, $unsafeKilogram, '100', $baseKey),
        productBomWeightPercentageRow($dependentMaterial, $localGram, $baseKey, '2'),
    ]));
    $uiEdges = app(ProductComponentUnitConversionService::class)
        ->globalEdgesForCompany((int) $this->bomWeightCompany->getKey());

    expect($errors)->toHaveKey('components.1.reference_component_key')
        ->and(collect($uiEdges)->contains(
            fn (array $edge): bool => $edge['from'] === $unsafeKilogram->doc_num
                && $edge['to'] === $localGram->doc_num,
        ))->toBeFalse();

    $deletedEquivalentUnit = productBomWeightUnit($this->bomWeightCompany, 'Deleted Equivalent');
    $productWithDeletedEquivalent = productBomWeightMaterial($this->bomWeightCompany, $unsafeKilogram, [
        'equivalent_value' => '1000',
        'equivalent_unit_id' => $deletedEquivalentUnit->getKey(),
    ]);
    $deletedEquivalentUnit->delete();

    expect(app(ProductComponentUnitConversionService::class)->productEdges($productWithDeletedEquivalent))->toBe([]);
});

test('cloning remaps percentage references exclusively to cloned lines', function () {
    $unit = productBomWeightUnit($this->bomWeightCompany, 'Kilogram');
    $source = productBomWeightProduct($this->bomWeightCompany);
    $target = productBomWeightProduct($this->bomWeightCompany);
    $baseMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $dependentMaterial = productBomWeightMaterial($this->bomWeightCompany, $unit);
    $baseKey = (string) Str::uuid();

    [$sourceBase, $sourceDependent] = $this->bomWeightService->sync($source, [
        productBomWeightDirectRow($baseMaterial, $unit, '100', $baseKey),
        productBomWeightPercentageRow($dependentMaterial, $unit, $baseKey, '2'),
    ]);

    $this->bomWeightService->clone($target, $source);

    $targetBase = ProductComponent::query()
        ->where('product_id', $target->getKey())
        ->where('component_product_id', $baseMaterial->getKey())
        ->firstOrFail();
    $targetDependent = ProductComponent::query()
        ->where('product_id', $target->getKey())
        ->where('component_product_id', $dependentMaterial->getKey())
        ->firstOrFail();

    expect($targetBase->public_id)->not->toBe($sourceBase->public_id)
        ->and($targetDependent->public_id)->not->toBe($sourceDependent->public_id)
        ->and($targetBase->calculation_method)->toBe(ProductComponent::CalculationDirect)
        ->and($targetDependent->calculation_method)->toBe(ProductComponent::CalculationPercentage)
        ->and((string) $targetDependent->percentage)->toBe('2.00000000')
        ->and((string) $targetDependent->quantity)->toBe('2.00000000')
        ->and($targetDependent->reference_component_id)->toBe($targetBase->getKey())
        ->and($targetDependent->reference_component_id)->not->toBe($sourceBase->getKey());
});
