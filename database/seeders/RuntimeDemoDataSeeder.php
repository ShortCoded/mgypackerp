<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Auth\Models\Role;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemCategory;
use Modules\Core\Models\ItemColor;
use Modules\Core\Models\ItemDecal;
use Modules\Core\Models\ItemGroup;
use Modules\Core\Models\ItemLookup;
use Modules\Core\Models\ItemModel;
use Modules\Core\Models\ItemOriginCountry;
use Modules\Core\Models\ItemSize;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\DocumentNumberService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RuntimeDemoDataSeeder extends Seeder
{
    use WithoutModelEvents;

    private const Marker = 'RuntimeDemoDataSeeder';

    private const DemoPassword = 'RuntimeDemo2026!';

    private ?DocumentNumberService $documentNumbers = null;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if ($this->shouldSkipProduction()) {
            $this->command?->warn('Runtime demo data seeder skipped in production. Set ALLOW_RUNTIME_DEMO_DATA=true to run it intentionally.');

            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function (): void {
            $companies = $this->seedCompanies();
            $branches = $this->seedBranches($companies);
            $periods = $this->seedFinancialPeriods($companies);
            $lookups = $this->seedItemLookups($companies);

            $products = $this->seedProducts($companies, $lookups);
            $this->seedProductComponents($products);
            $roles = $this->seedRoles($companies, $branches, $periods);
            $this->seedUsers($roles);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Runtime demo data is ready. Demo users use password: '.self::DemoPassword);
    }

    private function shouldSkipProduction(): bool
    {
        $allowed = filter_var((string) env('ALLOW_RUNTIME_DEMO_DATA', false), FILTER_VALIDATE_BOOLEAN);

        return app()->environment('production') && ! $allowed;
    }

    /**
     * @return array<string, Company>
     */
    private function seedCompanies(): array
    {
        $companies = [];

        foreach ($this->companyDefinitions() as $key => $attributes) {
            $companies[$key] = $this->persistCompany($attributes);
        }

        return $companies;
    }

    /**
     * @param  array<string, Company>  $companies
     * @return array<string, array<string, Branch>>
     */
    private function seedBranches(array $companies): array
    {
        $branches = [];

        foreach ($this->branchDefinitions() as $companyKey => $definitions) {
            foreach ($definitions as $key => $attributes) {
                $branches[$companyKey][$key] = $this->persistBranch($companies[$companyKey], $attributes);
            }
        }

        return $branches;
    }

    /**
     * @param  array<string, Company>  $companies
     * @return array<string, array<string, FinancialPeriod>>
     */
    private function seedFinancialPeriods(array $companies): array
    {
        $periods = [];

        foreach ($companies as $companyKey => $company) {
            foreach ($this->financialPeriodDefinitions() as $key => $attributes) {
                $periods[$companyKey][$key] = $this->persistFinancialPeriod($company, $attributes);
            }
        }

        return $periods;
    }

    /**
     * @param  array<string, Company>  $companies
     * @return array<string, array<string, array<string, ItemLookup>>>
     */
    private function seedItemLookups(array $companies): array
    {
        $lookups = [];

        foreach ($companies as $companyKey => $company) {
            foreach ($this->lookupDefinitions() as $group => $definition) {
                foreach ($definition['items'] as $key => $attributes) {
                    $lookups[$companyKey][$group][$key] = $this->persistLookup(
                        $definition['model'],
                        $definition['document_key'],
                        $company,
                        $attributes,
                    );
                }
            }
        }

        return $lookups;
    }

    /**
     * @param  array<string, Company>  $companies
     * @param  array<string, array<string, array<string, ItemLookup>>>  $lookups
     * @return array<string, array<string, Product>>
     */
    private function seedProducts(array $companies, array $lookups): array
    {
        $products = [];

        foreach ($this->productDefinitions() as $companyKey => $definitions) {
            foreach ($definitions as $key => $attributes) {
                $products[$companyKey][$key] = $this->persistProduct($companies[$companyKey], $lookups[$companyKey], $attributes);
            }
        }

        return $products;
    }

    /**
     * @param  array<string, array<string, Product>>  $products
     */
    private function seedProductComponents(array $products): void
    {
        foreach ($this->productComponentDefinitions() as $companyKey => $definitions) {
            foreach ($definitions as $productKey => $components) {
                foreach ($components as $attributes) {
                    $this->persistProductComponent(
                        $products[$companyKey][$productKey],
                        $products[$companyKey][$attributes['component']],
                        $attributes,
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, Company>  $companies
     * @param  array<string, array<string, Branch>>  $branches
     * @param  array<string, array<string, FinancialPeriod>>  $periods
     * @return array<string, Role>
     */
    private function seedRoles(array $companies, array $branches, array $periods): array
    {
        $roles = [];

        $roles['full'] = $this->persistRole(
            'runtime-demo-full-admin',
            [
                'notes' => $this->note('Unrestricted demo role for operating context and roles scope testing.'),
                'company_access_restricted' => false,
                'branch_access_restricted' => false,
                'financial_period_access_restricted' => false,
            ],
            $this->fullAdminPermissions(),
        );

        $roles['nile'] = $this->persistRole(
            'runtime-demo-nile-scope-admin',
            [
                'notes' => $this->note('Limited demo role for assigning scope only inside Nile Wood Industries.'),
                'company_access_restricted' => true,
                'branch_access_restricted' => true,
                'financial_period_access_restricted' => true,
            ],
            $this->limitedAdminPermissions(),
            [$companies['nile']->getKey()],
            [
                $branches['nile']['cairo_factory']->getKey(),
                $branches['nile']['ramadan_warehouse']->getKey(),
            ],
            [
                $periods['nile']['fy_2026']->getKey(),
                $periods['nile']['fy_2027']->getKey(),
            ],
        );

        $roles['delta'] = $this->persistRole(
            'runtime-demo-delta-operator',
            [
                'notes' => $this->note('Limited demo role for manual operating context and product navigation checks.'),
                'company_access_restricted' => true,
                'branch_access_restricted' => true,
                'financial_period_access_restricted' => true,
            ],
            $this->operatorPermissions(),
            [$companies['delta']->getKey()],
            [$branches['delta']['alex_showroom']->getKey()],
            [$periods['delta']['fy_2026']->getKey()],
        );

        return $roles;
    }

    /**
     * @param  array<string, Role>  $roles
     */
    private function seedUsers(array $roles): void
    {
        $this->persistUser([
            'name' => 'Runtime Demo Full Admin',
            'username' => 'runtime_demo_full',
            'email' => 'demo.full@shortcoded.test',
            'phone' => '+20 100 700 2601',
            'locale' => 'en',
            'notes' => $this->note('Unrestricted demo login for operating context and role scope testing.'),
        ], $roles['full']);

        $this->persistUser([
            'name' => 'Runtime Demo Nile Scope Admin',
            'username' => 'runtime_demo_nile',
            'email' => 'demo.nile@shortcoded.test',
            'phone' => '+20 100 700 2602',
            'locale' => 'en',
            'notes' => $this->note('Limited demo login scoped to Nile Wood Industries, two branches, and two periods.'),
        ], $roles['nile']);

        $this->persistUser([
            'name' => 'Runtime Demo Delta Operator',
            'username' => 'runtime_demo_delta',
            'email' => 'demo.delta@shortcoded.test',
            'phone' => '+20 100 700 2603',
            'locale' => 'ar',
            'notes' => $this->note('Arabic demo login scoped to the Delta Alexandria showroom and FY 2026.'),
        ], $roles['delta']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistCompany(array $attributes): Company
    {
        $company = Company::withTrashed()
            ->where('name', $attributes['name'])
            ->first() ?? new Company;

        $this->ensureDocumentNumber($company, 'companies', Company::class);

        $company->forceFill([
            ...$attributes,
            'status' => 'active',
            'is_main' => false,
            'country' => 'Egypt',
            'industry' => 'Furniture manufacturing',
            'activity_type' => 'Manufacturing and retail',
            'notes' => $attributes['notes'] ?? $this->note('Runtime demo company.'),
        ])->save();

        if ($company->trashed()) {
            $company->restore();
        }

        return $company->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistBranch(Company $company, array $attributes): Branch
    {
        $branch = Branch::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('name', $attributes['name'])
            ->first() ?? new Branch;

        $this->ensureDocumentNumber($branch, 'branches', Branch::class);

        $branch->forceFill([
            ...$attributes,
            'company_id' => $company->getKey(),
            'status' => 'active',
            'notes' => $attributes['notes'] ?? $this->note('Runtime demo branch.'),
        ])->save();

        if ($branch->trashed()) {
            $branch->restore();
        }

        return $branch->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistFinancialPeriod(Company $company, array $attributes): FinancialPeriod
    {
        $period = FinancialPeriod::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('name', $attributes['name'])
            ->first() ?? new FinancialPeriod;

        $this->ensureDocumentNumber($period, 'financial_periods', FinancialPeriod::class, $company->getKey());

        $period->forceFill([
            ...$attributes,
            'company_id' => $company->getKey(),
            'notes' => $attributes['notes'] ?? $this->note('Runtime demo financial period.'),
        ])->save();

        if ($period->trashed()) {
            $period->restore();
        }

        return $period->refresh();
    }

    /**
     * @param  class-string<ItemLookup>  $modelClass
     * @param  array<string, mixed>  $attributes
     */
    private function persistLookup(string $modelClass, string $documentKey, Company $company, array $attributes): ItemLookup
    {
        $lookup = $modelClass::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('name', $attributes['name'])
            ->first() ?? new $modelClass;

        $this->ensureDocumentNumber($lookup, $documentKey, $modelClass, $company->getKey());

        $lookup->forceFill([
            ...$attributes,
            'company_id' => $company->getKey(),
            'status' => 'active',
            'notes' => $attributes['notes'] ?? $this->note('Runtime demo item lookup.'),
        ])->save();

        if ($lookup->trashed()) {
            $lookup->restore();
        }

        return $lookup->refresh();
    }

    /**
     * @param  array<string, array<string, ItemLookup>>  $lookups
     * @param  array<string, mixed>  $attributes
     */
    private function persistProduct(Company $company, array $lookups, array $attributes): Product
    {
        $product = Product::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('name', $attributes['name'])
            ->first() ?? new Product;

        $this->ensureDocumentNumber($product, 'products', Product::class, $company->getKey());

        $product->forceFill([
            'company_id' => $company->getKey(),
            'name' => $attributes['name'],
            'item_unit_id' => $lookups['units'][$attributes['unit']]->getKey(),
            'item_size_id' => $this->lookupKey($lookups, 'sizes', $attributes['size'] ?? null),
            'item_color_id' => $this->lookupKey($lookups, 'colors', $attributes['color'] ?? null),
            'item_decal_id' => $this->lookupKey($lookups, 'decals', $attributes['decal'] ?? null),
            'item_model_id' => $this->lookupKey($lookups, 'models', $attributes['model'] ?? null),
            'item_origin_country_id' => $this->lookupKey($lookups, 'origin_countries', $attributes['origin_country'] ?? null),
            'item_category_id' => $lookups['categories'][$attributes['category']]->getKey(),
            'item_group_id' => $lookups['groups'][$attributes['group']]->getKey(),
            'barcode' => $attributes['barcode'] ?? null,
            'reorder_point' => $attributes['reorder_point'] ?? null,
            'item_classification' => $attributes['item_classification'] ?? Product::ClassificationFinishedProduct,
            'cost_as_inventory' => $attributes['cost_as_inventory'] ?? true,
            'is_displayable' => $attributes['is_displayable'] ?? true,
            'status' => 'active',
            'notes' => $attributes['notes'] ?? $this->note('Runtime demo product.'),
        ])->save();

        if ($product->trashed()) {
            $product->restore();
        }

        return $product->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistProductComponent(Product $product, Product $componentProduct, array $attributes): ProductComponent
    {
        $component = ProductComponent::withTrashed()
            ->where('product_id', $product->getKey())
            ->where('component_product_id', $componentProduct->getKey())
            ->first() ?? new ProductComponent;

        $component->forceFill([
            'public_id' => $component->public_id ?: (string) Str::uuid(),
            'company_id' => $product->company_id,
            'product_id' => $product->getKey(),
            'component_product_id' => $componentProduct->getKey(),
            'unit_id' => $componentProduct->item_unit_id,
            'quantity' => $attributes['quantity'],
            'notes' => $attributes['notes'] ?? $this->note('Runtime demo product component.'),
        ])->save();

        if ($component->trashed()) {
            $component->restore();
        }

        return $component->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $permissionNames
     * @param  list<int>  $companyIds
     * @param  list<int>  $branchIds
     * @param  list<int>  $financialPeriodIds
     */
    private function persistRole(
        string $name,
        array $attributes,
        array $permissionNames,
        array $companyIds = [],
        array $branchIds = [],
        array $financialPeriodIds = [],
    ): Role {
        $role = Role::withTrashed()
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->first() ?? new Role;

        $this->ensureDocumentNumber($role, 'roles', Role::class);

        $role->forceFill([
            'name' => $name,
            'guard_name' => 'web',
            ...$attributes,
        ])->save();

        if ($role->trashed()) {
            $role->restore();
        }

        $role->syncPermissions($this->permissions($permissionNames));
        $role->companyAccessCompanies()->sync($companyIds);
        $role->branchAccessBranches()->sync($branchIds);
        $role->financialPeriodAccessPeriods()->sync($financialPeriodIds);

        return $role->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistUser(array $attributes, Role $role): User
    {
        $user = User::withTrashed()
            ->where('email', $attributes['email'])
            ->first() ?? new User;

        $this->ensureDocumentNumber($user, 'users', User::class);

        if (! $user->exists) {
            $user->forceFill([
                ...$attributes,
                'password' => Hash::make(self::DemoPassword),
                'status' => 'active',
                'email_verified_at' => now(),
            ])->save();
        } else {
            $user->forceFill([
                'status' => 'active',
                'notes' => $attributes['notes'],
            ])->save();
        }

        if ($user->trashed()) {
            $user->restore();
        }

        $user->assignRole($role);

        return $user->refresh();
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel  $record
     * @param  class-string<TModel>  $modelClass
     */
    private function ensureDocumentNumber(Model $record, string $documentKey, string $modelClass, ?int $companyId = null): void
    {
        if ($record->doc_number !== null && $record->doc_num !== null) {
            return;
        }

        $record->forceFill(
            $companyId === null
                ? $this->documentNumberService()->next($documentKey, $modelClass)
                : $this->documentNumberService()->nextForCompany($documentKey, $modelClass, $companyId)
        );
    }

    private function documentNumberService(): DocumentNumberService
    {
        return $this->documentNumbers ??= app(DocumentNumberService::class);
    }

    /**
     * @param  array<string, array<string, ItemLookup>>  $lookups
     */
    private function lookupKey(array $lookups, string $group, ?string $key): ?int
    {
        if ($key === null) {
            return null;
        }

        return $lookups[$group][$key]->getKey();
    }

    /**
     * @param  list<string>  $permissionNames
     * @return list<Permission>
     */
    private function permissions(array $permissionNames): array
    {
        return collect($permissionNames)
            ->unique()
            ->values()
            ->map(fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'))
            ->all();
    }

    private function note(string $text): string
    {
        return self::Marker.': '.$text;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function companyDefinitions(): array
    {
        return [
            'nile' => [
                'name' => 'Nile Wood Industries - Runtime Demo',
                'legal_name' => 'Nile Wood Industries LLC',
                'commercial_name' => 'Nile Wood',
                'legal_form' => 'Limited liability company',
                'commercial_register_number' => 'RD-CR-1002601',
                'tax_card_number' => 'RD-TAX-1002601',
                'vat_registration_number' => 'RD-VAT-1002601',
                'phone' => '+20 2 2470 2601',
                'mobile' => '+20 100 700 2601',
                'whatsapp' => '+20 100 700 2601',
                'email' => 'runtime-demo-nile@example.test',
                'website' => 'https://nile-wood.example.test',
                'governorate' => 'Cairo',
                'city' => 'Cairo',
                'area' => 'Nasr City',
                'address' => 'Block 12, Industrial Services Zone, Nasr City, Cairo',
                'postal_code' => '11765',
                'business_description' => 'Manufactures bedrooms, wardrobes, dining rooms, and custom hotel furniture.',
                'notes' => $this->note('Primary runtime demo manufacturer with factory, warehouse, and showroom branches.'),
            ],
            'delta' => [
                'name' => 'Delta Home Furniture - Runtime Demo',
                'legal_name' => 'Delta Home Furniture SAE',
                'commercial_name' => 'Delta Home',
                'legal_form' => 'Joint-stock company',
                'commercial_register_number' => 'RD-CR-1002602',
                'tax_card_number' => 'RD-TAX-1002602',
                'vat_registration_number' => 'RD-VAT-1002602',
                'phone' => '+20 3 420 2602',
                'mobile' => '+20 111 700 2602',
                'whatsapp' => '+20 111 700 2602',
                'email' => 'runtime-demo-delta@example.test',
                'website' => 'https://delta-home.example.test',
                'governorate' => 'Alexandria',
                'city' => 'Alexandria',
                'area' => 'Smouha',
                'address' => '14 Victor Emmanuel Square, Smouha, Alexandria',
                'postal_code' => '21615',
                'business_description' => 'Produces residential sofa frames, bedside tables, and upholstered furniture.',
                'notes' => $this->note('Runtime demo company for Alexandria workshop and showroom scope testing.'),
            ],
            'export' => [
                'name' => 'Damietta Export Furniture - Runtime Demo',
                'legal_name' => 'Damietta Export Furniture LLC',
                'commercial_name' => 'Damietta Export',
                'legal_form' => 'Limited liability company',
                'commercial_register_number' => 'RD-CR-1002603',
                'tax_card_number' => 'RD-TAX-1002603',
                'vat_registration_number' => 'RD-VAT-1002603',
                'phone' => '+20 57 240 2603',
                'mobile' => '+20 122 700 2603',
                'whatsapp' => '+20 122 700 2603',
                'email' => 'runtime-demo-export@example.test',
                'website' => 'https://damietta-export.example.test',
                'governorate' => 'Damietta',
                'city' => 'Damietta',
                'area' => 'Furniture City',
                'address' => 'Export Services District, Damietta Furniture City',
                'postal_code' => '34511',
                'business_description' => 'Prepares export packaging, hotel starter sets, and container-ready shipments.',
                'notes' => $this->note('Runtime demo export services company for multi-company selector checks.'),
            ],
        ];
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function branchDefinitions(): array
    {
        return [
            'nile' => [
                'cairo_factory' => [
                    'name' => 'Cairo Factory - Runtime Demo',
                    'type' => Branch::TypeFactory,
                    'address' => 'Factory 7, 10th of Ramadan Industrial Zone, Cairo',
                    'phone' => '+20 2 2470 2611',
                    'mobile' => '+20 100 700 2611',
                    'email' => 'runtime-demo-cairo-factory@example.test',
                    'contact_person' => 'Mona Farouk',
                    'notes' => $this->note('Production branch for limited Nile operating scope.'),
                ],
                'ramadan_warehouse' => [
                    'name' => '10th of Ramadan Warehouse - Runtime Demo',
                    'type' => Branch::TypeWarehouse,
                    'address' => 'Warehouse B4, 10th of Ramadan Logistics Area',
                    'phone' => '+20 2 2470 2612',
                    'mobile' => '+20 100 700 2612',
                    'email' => 'runtime-demo-ramadan-warehouse@example.test',
                    'contact_person' => 'Karim Samir',
                    'notes' => $this->note('Warehouse branch for inventory and operating context checks.'),
                ],
                'nasr_showroom' => [
                    'name' => 'Nasr City Showroom - Runtime Demo',
                    'type' => Branch::TypeShowroom,
                    'address' => '18 Abbas El Akkad Street, Nasr City, Cairo',
                    'phone' => '+20 2 2470 2613',
                    'mobile' => '+20 100 700 2613',
                    'email' => 'runtime-demo-nasr-showroom@example.test',
                    'contact_person' => 'Youssef Adel',
                    'notes' => $this->note('Nile showroom intentionally outside the limited Nile scope role.'),
                ],
            ],
            'delta' => [
                'alex_workshop' => [
                    'name' => 'Alexandria Workshop - Runtime Demo',
                    'type' => Branch::TypeFactory,
                    'address' => 'Workshop 22, Borg El Arab Industrial Zone, Alexandria',
                    'phone' => '+20 3 420 2611',
                    'mobile' => '+20 111 700 2611',
                    'email' => 'runtime-demo-alex-workshop@example.test',
                    'contact_person' => 'Nour Hassan',
                    'notes' => $this->note('Delta production branch outside the Delta operator scope.'),
                ],
                'alex_showroom' => [
                    'name' => 'Alexandria Showroom - Runtime Demo',
                    'type' => Branch::TypeShowroom,
                    'address' => '26 Fouad Street, Raml Station, Alexandria',
                    'phone' => '+20 3 420 2612',
                    'mobile' => '+20 111 700 2612',
                    'email' => 'runtime-demo-alex-showroom@example.test',
                    'contact_person' => 'Salma Nabil',
                    'notes' => $this->note('Only branch granted to the Delta operator role.'),
                ],
            ],
            'export' => [
                'damietta_office' => [
                    'name' => 'Damietta Export Office - Runtime Demo',
                    'type' => Branch::TypeAdministrative,
                    'address' => 'Administrative Building 3, Damietta Furniture City',
                    'phone' => '+20 57 240 2611',
                    'mobile' => '+20 122 700 2611',
                    'email' => 'runtime-demo-damietta-office@example.test',
                    'contact_person' => 'Hany Refaat',
                    'notes' => $this->note('Export administration branch for full-scope checks.'),
                ],
                'port_warehouse' => [
                    'name' => 'Port Said Warehouse - Runtime Demo',
                    'type' => Branch::TypeWarehouse,
                    'address' => 'Warehouse 5, Port Said Free Zone',
                    'phone' => '+20 66 330 2612',
                    'mobile' => '+20 122 700 2612',
                    'email' => 'runtime-demo-port-warehouse@example.test',
                    'contact_person' => 'Amr Shaker',
                    'notes' => $this->note('Export warehouse branch for unrestricted role checks.'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function financialPeriodDefinitions(): array
    {
        return [
            'fy_2025' => [
                'name' => 'FY 2025 - Runtime Demo',
                'from_date' => CarbonImmutable::create(2025, 1, 1)->toDateString(),
                'to_date' => CarbonImmutable::create(2025, 12, 31)->toDateString(),
                'is_closed' => true,
                'allows_opening_entries' => false,
                'notes' => $this->note('Closed prior fiscal year for selector and filtering checks.'),
            ],
            'fy_2026' => [
                'name' => 'FY 2026 - Runtime Demo',
                'from_date' => CarbonImmutable::create(2026, 1, 1)->toDateString(),
                'to_date' => CarbonImmutable::create(2026, 12, 31)->toDateString(),
                'is_closed' => false,
                'allows_opening_entries' => true,
                'notes' => $this->note('Current open fiscal year for operating context testing.'),
            ],
            'fy_2027' => [
                'name' => 'FY 2027 - Runtime Demo',
                'from_date' => CarbonImmutable::create(2027, 1, 1)->toDateString(),
                'to_date' => CarbonImmutable::create(2027, 12, 31)->toDateString(),
                'is_closed' => false,
                'allows_opening_entries' => true,
                'notes' => $this->note('Next open fiscal year for multi-period access testing.'),
            ],
        ];
    }

    /**
     * @return array<string, array{model: class-string<ItemLookup>, document_key: string, items: array<string, array<string, mixed>>}>
     */
    private function lookupDefinitions(): array
    {
        return [
            'units' => [
                'model' => ItemUnit::class,
                'document_key' => 'item_units',
                'items' => [
                    'piece' => ['name' => 'Piece', 'notes' => $this->note('Single finished furniture item.')],
                    'set' => ['name' => 'Set', 'notes' => $this->note('Bundled furniture set sold together.')],
                    'square_meter' => ['name' => 'Square Meter', 'notes' => $this->note('Surface material measurement.')],
                    'linear_meter' => ['name' => 'Linear Meter', 'notes' => $this->note('Fabric, trim, and edge banding measurement.')],
                    'kilogram' => ['name' => 'Kilogram', 'notes' => $this->note('Adhesives, foam, and bulk material measurement.')],
                    'cubic_meter' => ['name' => 'Cubic Meter', 'notes' => $this->note('Bulk wood and packing volume measurement.')],
                ],
            ],
            'sizes' => [
                'model' => ItemSize::class,
                'document_key' => 'item_sizes',
                'items' => [
                    'standard_60_40' => ['name' => 'Standard 60x40 cm', 'notes' => $this->note('Small furniture component size.')],
                    'wardrobe_240_180' => ['name' => 'Wardrobe 240x180 cm', 'notes' => $this->note('Four-door wardrobe finished size.')],
                    'dining_180_90' => ['name' => 'Dining Table 180x90 cm', 'notes' => $this->note('Six-seat dining table top size.')],
                    'king_bed_200_180' => ['name' => 'King Bed 200x180 cm', 'notes' => $this->note('Hotel and bedroom king bed size.')],
                    'sofa_220' => ['name' => 'Sofa Frame 220 cm', 'notes' => $this->note('Three-seat sofa frame size.')],
                ],
            ],
            'colors' => [
                'model' => ItemColor::class,
                'document_key' => 'item_colors',
                'items' => [
                    'natural_oak' => ['name' => 'Natural Oak', 'notes' => $this->note('Light oak veneer finish.')],
                    'walnut_brown' => ['name' => 'Walnut Brown', 'notes' => $this->note('Medium walnut stain finish.')],
                    'matte_white' => ['name' => 'Matte White', 'notes' => $this->note('Painted matte white finish.')],
                    'charcoal_gray' => ['name' => 'Charcoal Gray', 'notes' => $this->note('Modern dark gray finish.')],
                    'linen_beige' => ['name' => 'Linen Beige', 'notes' => $this->note('Neutral upholstery fabric color.')],
                ],
            ],
            'decals' => [
                'model' => ItemDecal::class,
                'document_key' => 'item_decals',
                'items' => [
                    'oak_grain' => ['name' => 'Oak Grain', 'notes' => $this->note('Oak grain decorative decal.')],
                    'walnut_grain' => ['name' => 'Walnut Grain', 'notes' => $this->note('Walnut grain decorative decal.')],
                    'linen_texture' => ['name' => 'Linen Texture', 'notes' => $this->note('Soft fabric texture decal.')],
                ],
            ],
            'models' => [
                'model' => ItemModel::class,
                'document_key' => 'item_models',
                'items' => [
                    'classic_raised_panel' => ['name' => 'Classic Raised Panel', 'notes' => $this->note('Traditional routed panel design.')],
                    'modern_flat_panel' => ['name' => 'Modern Flat Panel', 'notes' => $this->note('Flat contemporary furniture fronts.')],
                    'scandinavian_minimal' => ['name' => 'Scandinavian Minimal', 'notes' => $this->note('Minimal lines with light wood tones.')],
                    'hotel_contract' => ['name' => 'Hotel Contract', 'notes' => $this->note('Durable hospitality furniture specification.')],
                ],
            ],
            'categories' => [
                'model' => ItemCategory::class,
                'document_key' => 'item_categories',
                'items' => [
                    'bedroom' => ['name' => 'Bedroom Furniture', 'notes' => $this->note('Beds, wardrobes, nightstands, and dressers.')],
                    'dining' => ['name' => 'Dining Furniture', 'notes' => $this->note('Tables, chairs, and dining room sets.')],
                    'living' => ['name' => 'Living Room Furniture', 'notes' => $this->note('Sofas, TV units, and coffee tables.')],
                    'raw_wood' => ['name' => 'Raw Wood', 'notes' => $this->note('Boards, veneer, MDF, and timber materials.')],
                    'upholstery' => ['name' => 'Upholstery Materials', 'notes' => $this->note('Fabric, foam, and padding materials.')],
                    'hardware' => ['name' => 'Hardware and Accessories', 'notes' => $this->note('Hinges, handles, slides, and fittings.')],
                ],
            ],
            'groups' => [
                'model' => ItemGroup::class,
                'document_key' => 'item_groups',
                'items' => [
                    'finished_goods' => ['name' => 'Finished Goods', 'notes' => $this->note('Sellable finished products.')],
                    'raw_materials' => ['name' => 'Raw Materials', 'notes' => $this->note('Materials consumed by production.')],
                    'semi_finished' => ['name' => 'Semi-Finished Components', 'notes' => $this->note('Frames and components awaiting finishing.')],
                    'export_collection' => ['name' => 'Export Collection', 'notes' => $this->note('Products prepared for export orders.')],
                ],
            ],
            'origin_countries' => [
                'model' => ItemOriginCountry::class,
                'document_key' => 'item_origin_countries',
                'items' => [
                    'egypt' => ['name' => 'Egypt', 'notes' => $this->note('Local production origin.')],
                    'turkey' => ['name' => 'Turkey', 'notes' => $this->note('Imported hardware and accessories origin.')],
                    'italy' => ['name' => 'Italy', 'notes' => $this->note('Imported fabric and finishing material origin.')],
                    'china' => ['name' => 'China', 'notes' => $this->note('Packaging and hardware import origin.')],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function productDefinitions(): array
    {
        return [
            'nile' => [
                'oak_wardrobe' => [
                    'name' => 'Oak Wardrobe 4 Door - Runtime Demo',
                    'item_classification' => Product::ClassificationFinishedProduct,
                    'barcode' => 'NILE-WARD-0001',
                    'reorder_point' => '4.0000',
                    'unit' => 'piece',
                    'size' => 'wardrobe_240_180',
                    'color' => 'natural_oak',
                    'decal' => 'oak_grain',
                    'model' => 'classic_raised_panel',
                    'origin_country' => 'egypt',
                    'category' => 'bedroom',
                    'group' => 'finished_goods',
                    'notes' => $this->note('Finished four-door wardrobe for showroom and inventory checks.'),
                ],
                'walnut_dining_table' => [
                    'name' => 'Walnut Dining Table 6 Seats - Runtime Demo',
                    'item_classification' => Product::ClassificationFinishedProduct,
                    'barcode' => 'NILE-DIN-0001',
                    'reorder_point' => '3.0000',
                    'unit' => 'piece',
                    'size' => 'dining_180_90',
                    'color' => 'walnut_brown',
                    'decal' => 'walnut_grain',
                    'model' => 'modern_flat_panel',
                    'origin_country' => 'egypt',
                    'category' => 'dining',
                    'group' => 'finished_goods',
                    'notes' => $this->note('Finished dining table tied to Nile company lookups.'),
                ],
                'beech_board' => [
                    'name' => 'Beech Wood Board 18mm - Runtime Demo',
                    'item_classification' => Product::ClassificationRawMaterial,
                    'barcode' => 'NILE-RAW-BOARD-18',
                    'reorder_point' => '120.0000',
                    'unit' => 'square_meter',
                    'color' => 'natural_oak',
                    'origin_country' => 'egypt',
                    'category' => 'raw_wood',
                    'group' => 'raw_materials',
                    'notes' => $this->note('Raw board material for costing and stock tests.'),
                ],
                'hinge_set' => [
                    'name' => 'Soft-Close Hinge Set - Runtime Demo',
                    'item_classification' => Product::ClassificationRawMaterial,
                    'barcode' => 'NILE-HINGE-SET',
                    'reorder_point' => '80.0000',
                    'unit' => 'set',
                    'origin_country' => 'turkey',
                    'category' => 'hardware',
                    'group' => 'raw_materials',
                    'notes' => $this->note('Hardware material used in wardrobes and cabinets.'),
                ],
            ],
            'delta' => [
                'sofa_frame' => [
                    'name' => 'Alexandria Sofa Frame 3 Seat - Runtime Demo',
                    'item_classification' => Product::ClassificationSemiFinished,
                    'barcode' => 'DELTA-SOFA-FRAME',
                    'reorder_point' => '6.0000',
                    'unit' => 'piece',
                    'size' => 'sofa_220',
                    'color' => 'charcoal_gray',
                    'decal' => 'linen_texture',
                    'model' => 'modern_flat_panel',
                    'origin_country' => 'egypt',
                    'category' => 'living',
                    'group' => 'semi_finished',
                    'notes' => $this->note('Semi-finished sofa frame for Delta workshop checks.'),
                ],
                'bedside_table' => [
                    'name' => 'Matte White Bedside Table - Runtime Demo',
                    'item_classification' => Product::ClassificationFinishedProduct,
                    'barcode' => 'DELTA-BED-0001',
                    'reorder_point' => '10.0000',
                    'unit' => 'piece',
                    'size' => 'standard_60_40',
                    'color' => 'matte_white',
                    'model' => 'scandinavian_minimal',
                    'origin_country' => 'egypt',
                    'category' => 'bedroom',
                    'group' => 'finished_goods',
                    'notes' => $this->note('Finished bedside table for Delta product list checks.'),
                ],
                'linen_roll' => [
                    'name' => 'Linen Upholstery Roll - Runtime Demo',
                    'item_classification' => Product::ClassificationRawMaterial,
                    'barcode' => 'DELTA-LINEN-ROLL',
                    'reorder_point' => '50.0000',
                    'unit' => 'linear_meter',
                    'color' => 'linen_beige',
                    'origin_country' => 'italy',
                    'category' => 'upholstery',
                    'group' => 'raw_materials',
                    'notes' => $this->note('Fabric roll material for upholstery product checks.'),
                ],
            ],
            'export' => [
                'wooden_crate' => [
                    'name' => 'Export Packing Wooden Crate - Runtime Demo',
                    'item_classification' => Product::ClassificationPackaging,
                    'barcode' => 'EXP-CRATE-0001',
                    'reorder_point' => '25.0000',
                    'unit' => 'cubic_meter',
                    'origin_country' => 'egypt',
                    'category' => 'raw_wood',
                    'group' => 'export_collection',
                    'notes' => $this->note('Export crate material for Damietta shipment testing.'),
                ],
                'hotel_room_set' => [
                    'name' => 'Hotel Room Starter Set - Runtime Demo',
                    'item_classification' => Product::ClassificationFinishedProduct,
                    'barcode' => 'EXP-HOTEL-SET',
                    'reorder_point' => '2.0000',
                    'unit' => 'set',
                    'size' => 'king_bed_200_180',
                    'color' => 'walnut_brown',
                    'decal' => 'walnut_grain',
                    'model' => 'hotel_contract',
                    'origin_country' => 'egypt',
                    'category' => 'bedroom',
                    'group' => 'export_collection',
                    'notes' => $this->note('Contract furniture bundle for export company checks.'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function productComponentDefinitions(): array
    {
        return [
            'nile' => [
                'oak_wardrobe' => [
                    ['component' => 'beech_board', 'quantity' => '6.5000', 'notes' => $this->note('Board requirement for one wardrobe.')],
                    ['component' => 'hinge_set', 'quantity' => '4.0000', 'notes' => $this->note('Soft-close hinges for one wardrobe.')],
                ],
                'walnut_dining_table' => [
                    ['component' => 'beech_board', 'quantity' => '3.2500', 'notes' => $this->note('Board requirement for one dining table.')],
                ],
            ],
            'delta' => [
                'sofa_frame' => [
                    ['component' => 'linen_roll', 'quantity' => '5.5000', 'notes' => $this->note('Upholstery fabric for one sofa frame.')],
                ],
            ],
            'export' => [
                'hotel_room_set' => [
                    ['component' => 'wooden_crate', 'quantity' => '1.7500', 'notes' => $this->note('Packing crate volume for one hotel set.')],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function fullAdminPermissions(): array
    {
        return array_values(array_unique([
            ...$this->operatorPermissions(),
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.operating_scope.manage',
            'users.view',
            'users.create',
            'users.edit',
            'users.roles.manage',
        ]));
    }

    /**
     * @return list<string>
     */
    private function limitedAdminPermissions(): array
    {
        return array_values(array_unique([
            ...$this->operatorPermissions(),
            'roles.view',
            'roles.create',
            'roles.edit',
            'roles.operating_scope.manage',
        ]));
    }

    /**
     * @return list<string>
     */
    private function operatorPermissions(): array
    {
        return [
            'dashboard.view',
            'companies.view',
            'branches.view',
            'financial_periods.view',
            'products.view',
            'products.create',
            'products.edit',
            'item_units.view',
            'item_units.create',
            'item_units.edit',
            'item_sizes.view',
            'item_sizes.create',
            'item_sizes.edit',
            'item_colors.view',
            'item_colors.create',
            'item_colors.edit',
            'item_decals.view',
            'item_decals.create',
            'item_decals.edit',
            'item_models.view',
            'item_models.create',
            'item_models.edit',
            'item_categories.view',
            'item_categories.create',
            'item_categories.edit',
            'item_groups.view',
            'item_groups.create',
            'item_groups.edit',
            'item_origin_countries.view',
            'item_origin_countries.create',
            'item_origin_countries.edit',
        ];
    }
}
