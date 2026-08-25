<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Accounting\Database\Seeders\BaselineCostCentersSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\PermissionRegistryService;
use Modules\Core\Database\Seeders\CurrencySeeder;
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

        $this->call(PermissionSeeder::class);
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

        $this->call([
            DefaultChartOfAccountsSeeder::class,
            BaselineCostCentersSeeder::class,
            CurrencySeeder::class,
            IntegratedPlasticFactorySeeder::class,
        ]);

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
            'name' => 'Runtime Demo Browser Verifier',
            'username' => 'runtime_demo_browser',
            'email' => 'demo.browser@shortcoded.test',
            'phone' => '+20 100 700 2699',
            'locale' => 'en',
            'notes' => $this->note('Dedicated unrestricted login for repeatable browser and PDF acceptance checks.'),
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
        $company = Company::withoutGlobalScopes()
            ->withTrashed()
            ->where('name', $attributes['name'])
            ->first() ?? new Company;

        $this->ensureDocumentNumber($company, 'companies', Company::class);

        $company->forceFill([
            ...$attributes,
            'status' => 'active',
            'is_main' => false,
            'country' => 'Egypt',
            'industry' => $attributes['industry'] ?? 'Plastic products manufacturing',
            'activity_type' => $attributes['activity_type'] ?? 'Manufacturing and wholesale',
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
            ->where(function ($query) use ($attributes): void {
                $query->where('name', $attributes['name']);

                if (filled($attributes['barcode'] ?? null)) {
                    $query->orWhere('barcode', $attributes['barcode']);
                }
            })
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

        $role->permissions()->sync($this->permissionIds($permissionNames));
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
     * @return list<int>
     */
    private function permissionIds(array $permissionNames): array
    {
        $permissionNames = collect($permissionNames)
            ->unique()
            ->values()
            ->all();
        $permissionTable = (new Permission)->getTable();
        $existingNames = DB::table($permissionTable)
            ->where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->pluck('name')
            ->all();
        $timestamp = now();

        foreach (array_diff($permissionNames, $existingNames) as $permissionName) {
            DB::table($permissionTable)->insertOrIgnore([
                'name' => $permissionName,
                'guard_name' => 'web',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        return DB::table($permissionTable)
            ->where('guard_name', 'web')
            ->whereIn('name', $permissionNames)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
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
                'name' => 'Mgy Plast Manufacturing - Runtime Demo',
                'legal_name' => 'Mgy Plast Manufacturing LLC',
                'commercial_name' => 'Mgy Plast',
                'legal_form' => 'Limited liability company',
                'commercial_register_number' => 'RD-CR-1002601',
                'tax_card_number' => 'RD-TAX-1002601',
                'vat_registration_number' => 'RD-VAT-1002601',
                'phone' => '+20 2 2470 2601',
                'mobile' => '+20 100 700 2601',
                'whatsapp' => '+20 100 700 2601',
                'email' => 'runtime-demo-mgy-plast@example.test',
                'website' => 'https://mgy-plast.example.test',
                'governorate' => 'Sharqia',
                'city' => '10th of Ramadan',
                'area' => 'Industrial Zone A3',
                'address' => 'Factory 18, Industrial Zone A3, 10th of Ramadan City',
                'postal_code' => '44629',
                'business_description' => 'Manufactures injection-molded pails, food containers, lids, and industrial plastic packaging.',
                'industry' => 'Plastic packaging manufacturing',
                'activity_type' => 'Manufacturing and wholesale',
                'notes' => $this->note('Primary plastic factory used by every integrated golden business cycle.'),
            ],
            'delta' => [
                'name' => 'Delta Packaging Trading - Runtime Demo',
                'legal_name' => 'Delta Packaging Trading SAE',
                'commercial_name' => 'Delta Packaging',
                'legal_form' => 'Joint-stock company',
                'commercial_register_number' => 'RD-CR-1002602',
                'tax_card_number' => 'RD-TAX-1002602',
                'vat_registration_number' => 'RD-VAT-1002602',
                'phone' => '+20 3 420 2602',
                'mobile' => '+20 111 700 2602',
                'whatsapp' => '+20 111 700 2602',
                'email' => 'runtime-demo-delta@example.test',
                'website' => 'https://delta-packaging.example.test',
                'governorate' => 'Alexandria',
                'city' => 'Alexandria',
                'area' => 'Smouha',
                'address' => '14 Victor Emmanuel Square, Smouha, Alexandria',
                'postal_code' => '21615',
                'business_description' => 'Distributes plastic packaging, closures, and food-grade containers across the Delta.',
                'industry' => 'Plastic packaging distribution',
                'activity_type' => 'Wholesale and distribution',
                'notes' => $this->note('Secondary packaging distributor for restricted operating-scope testing.'),
            ],
            'export' => [
                'name' => 'Alexandria Plastics Export - Runtime Demo',
                'legal_name' => 'Alexandria Plastics Export LLC',
                'commercial_name' => 'Alex Plast Export',
                'legal_form' => 'Limited liability company',
                'commercial_register_number' => 'RD-CR-1002603',
                'tax_card_number' => 'RD-TAX-1002603',
                'vat_registration_number' => 'RD-VAT-1002603',
                'phone' => '+20 57 240 2603',
                'mobile' => '+20 122 700 2603',
                'whatsapp' => '+20 122 700 2603',
                'email' => 'runtime-demo-export@example.test',
                'website' => 'https://alex-plast-export.example.test',
                'governorate' => 'Alexandria',
                'city' => 'Alexandria',
                'area' => 'Amreya Free Zone',
                'address' => 'Export Warehouse 9, Amreya Free Zone, Alexandria',
                'postal_code' => '21934',
                'business_description' => 'Exports food-grade plastic containers and industrial pails to regional markets.',
                'industry' => 'Plastic products export',
                'activity_type' => 'Export and logistics',
                'notes' => $this->note('Export company for multi-company selectors and unrestricted-scope checks.'),
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
                    'name' => '10th of Ramadan Plastic Factory - Runtime Demo',
                    'type' => Branch::TypeFactory,
                    'address' => 'Factory 18, Industrial Zone A3, 10th of Ramadan City',
                    'phone' => '+20 2 2470 2611',
                    'mobile' => '+20 100 700 2611',
                    'email' => 'runtime-demo-cairo-factory@example.test',
                    'contact_person' => 'Mona Farouk',
                    'notes' => $this->note('Primary injection-molding factory for the integrated demo cycles.'),
                ],
                'ramadan_warehouse' => [
                    'name' => '10th of Ramadan Distribution Warehouse - Runtime Demo',
                    'type' => Branch::TypeWarehouse,
                    'address' => 'Warehouse B4, 10th of Ramadan Logistics Area',
                    'phone' => '+20 2 2470 2612',
                    'mobile' => '+20 100 700 2612',
                    'email' => 'runtime-demo-ramadan-warehouse@example.test',
                    'contact_person' => 'Karim Samir',
                    'notes' => $this->note('Warehouse branch for inventory and operating context checks.'),
                ],
                'nasr_showroom' => [
                    'name' => 'Cairo Sales Office - Runtime Demo',
                    'type' => Branch::TypeAdministrative,
                    'address' => '18 Abbas El Akkad Street, Nasr City, Cairo',
                    'phone' => '+20 2 2470 2613',
                    'mobile' => '+20 100 700 2613',
                    'email' => 'runtime-demo-nasr-showroom@example.test',
                    'contact_person' => 'Youssef Adel',
                    'notes' => $this->note('Sales office intentionally outside the limited factory scope role.'),
                ],
            ],
            'delta' => [
                'alex_workshop' => [
                    'name' => 'Alexandria Packaging Warehouse - Runtime Demo',
                    'type' => Branch::TypeWarehouse,
                    'address' => 'Warehouse 22, Borg El Arab Industrial Zone, Alexandria',
                    'phone' => '+20 3 420 2611',
                    'mobile' => '+20 111 700 2611',
                    'email' => 'runtime-demo-alex-workshop@example.test',
                    'contact_person' => 'Nour Hassan',
                    'notes' => $this->note('Delta distribution warehouse outside the operator scope.'),
                ],
                'alex_showroom' => [
                    'name' => 'Alexandria Packaging Sales Office - Runtime Demo',
                    'type' => Branch::TypeAdministrative,
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
                    'name' => 'Alexandria Export Office - Runtime Demo',
                    'type' => Branch::TypeAdministrative,
                    'address' => 'Administrative Building 3, Amreya Free Zone',
                    'phone' => '+20 57 240 2611',
                    'mobile' => '+20 122 700 2611',
                    'email' => 'runtime-demo-damietta-office@example.test',
                    'contact_person' => 'Hany Refaat',
                    'notes' => $this->note('Export administration branch for full-scope checks.'),
                ],
                'port_warehouse' => [
                    'name' => 'Alexandria Port Warehouse - Runtime Demo',
                    'type' => Branch::TypeWarehouse,
                    'address' => 'Warehouse 5, Alexandria Port Free Zone',
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
                    'carton' => ['name' => 'Carton', 'notes' => $this->note('Outer packaging carton measurement.')],
                    'metric_ton' => ['name' => 'Metric Ton', 'notes' => $this->note('Bulk polymer resin purchasing measurement.')],
                    'gram' => ['name' => 'Gram', 'notes' => $this->note('Small additive and sample-weight measurement.')],
                    'bag' => ['name' => 'Bag', 'notes' => $this->note('Resin and printed packaging bag measurement.')],
                    'roll' => ['name' => 'Roll', 'notes' => $this->note('Tape, labels, and film roll measurement.')],
                    'pallet' => ['name' => 'Pallet', 'notes' => $this->note('Palletized finished-goods and bulk shipment measurement.')],
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
                    'pail_20l' => ['name' => '20 Liter Pail', 'notes' => $this->note('Industrial pail nominal capacity.')],
                    'container_5l' => ['name' => '5 Liter Container', 'notes' => $this->note('Food-grade container nominal capacity.')],
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
                    'royal_blue' => ['name' => 'Royal Blue', 'notes' => $this->note('Industrial pail masterbatch color.')],
                    'transparent' => ['name' => 'Transparent', 'notes' => $this->note('Natural food-grade polypropylene finish.')],
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
                    'injection_pail' => ['name' => 'Injection Pail Series', 'notes' => $this->note('Injection-molded pail and lid family.')],
                    'food_container' => ['name' => 'Food Container Series', 'notes' => $this->note('Food-contact injection-molded container family.')],
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
                    'polymer_resins' => ['name' => 'Polymer Resins', 'notes' => $this->note('Virgin and recycled polymer feedstock.')],
                    'plastic_additives' => ['name' => 'Plastic Additives', 'notes' => $this->note('Masterbatch, stabilizers, and processing additives.')],
                    'industrial_pails' => ['name' => 'Industrial Plastic Pails', 'notes' => $this->note('Paint, chemical, and lubricant packaging.')],
                    'food_packaging' => ['name' => 'Food-Grade Packaging', 'notes' => $this->note('Food-contact containers and closures.')],
                    'packing_materials' => ['name' => 'Packing Materials', 'notes' => $this->note('Cartons, labels, stretch film, and pallets.')],
                    'production_services' => ['name' => 'Production Services', 'notes' => $this->note('Setup, delivery, and technical services.')],
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
                    'virgin_materials' => ['name' => 'Virgin Raw Materials', 'notes' => $this->note('Certified virgin polymer inventory.')],
                    'additives' => ['name' => 'Color and Process Additives', 'notes' => $this->note('Masterbatch and process additive inventory.')],
                    'plastic_finished_goods' => ['name' => 'Plastic Finished Goods', 'notes' => $this->note('Sellable molded plastic products.')],
                    'packaging_consumables' => ['name' => 'Packaging Consumables', 'notes' => $this->note('Packing material consumed per production unit.')],
                    'services' => ['name' => 'Services', 'notes' => $this->note('Non-stock sale and purchase services.')],
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
                'industrial_pail' => [
                    'name' => '20L Blue Industrial Pail with Lid - Runtime Demo',
                    'item_classification' => Product::ClassificationFinishedProduct,
                    'barcode' => 'MGY-FG-PAIL-20L-BLU',
                    'reorder_point' => '500.0000',
                    'unit' => 'piece',
                    'size' => 'pail_20l',
                    'color' => 'royal_blue',
                    'model' => 'injection_pail',
                    'origin_country' => 'egypt',
                    'category' => 'industrial_pails',
                    'group' => 'plastic_finished_goods',
                    'notes' => $this->note('Primary saleable item used by procurement, production, inventory, sales, quality, and accounting cycles.'),
                ],
                'food_container' => [
                    'name' => '5L Transparent Food Container - Runtime Demo',
                    'item_classification' => Product::ClassificationFinishedProduct,
                    'barcode' => 'MGY-FG-FOOD-5L-CLR',
                    'reorder_point' => '750.0000',
                    'unit' => 'piece',
                    'size' => 'container_5l',
                    'color' => 'transparent',
                    'model' => 'food_container',
                    'origin_country' => 'egypt',
                    'category' => 'food_packaging',
                    'group' => 'plastic_finished_goods',
                    'notes' => $this->note('Secondary finished product for stock, reorder, and sales selection tests.'),
                ],
                'pp_resin' => [
                    'name' => 'PP Homopolymer Injection Grade - Runtime Demo',
                    'item_classification' => Product::ClassificationRawMaterial,
                    'barcode' => 'MGY-RM-PP-HOMO-001',
                    'reorder_point' => '3000.0000',
                    'unit' => 'kilogram',
                    'color' => 'transparent',
                    'origin_country' => 'egypt',
                    'category' => 'polymer_resins',
                    'group' => 'virgin_materials',
                    'notes' => $this->note('Primary resin purchased, received, inspected, stocked, and consumed in production.'),
                ],
                'blue_masterbatch' => [
                    'name' => 'Blue Color Masterbatch - Runtime Demo',
                    'item_classification' => Product::ClassificationRawMaterial,
                    'barcode' => 'MGY-RM-MB-BLUE-001',
                    'reorder_point' => '100.0000',
                    'unit' => 'kilogram',
                    'color' => 'royal_blue',
                    'origin_country' => 'turkey',
                    'category' => 'plastic_additives',
                    'group' => 'additives',
                    'notes' => $this->note('Color additive consumed by the industrial pail BOM.'),
                ],
                'packing_carton' => [
                    'name' => 'Printed Pail Packing Carton - Runtime Demo',
                    'item_classification' => Product::ClassificationPackaging,
                    'barcode' => 'MGY-PK-CARTON-PAIL-24',
                    'reorder_point' => '500.0000',
                    'unit' => 'carton',
                    'origin_country' => 'egypt',
                    'category' => 'packing_materials',
                    'group' => 'packaging_consumables',
                    'notes' => $this->note('One carton packs twenty-four finished pails.'),
                ],
                'delivery_service' => [
                    'name' => 'Plastic Products Delivery Service - Runtime Demo',
                    'item_classification' => Product::ClassificationService,
                    'barcode' => 'MGY-SVC-DELIVERY',
                    'reorder_point' => null,
                    'unit' => 'piece',
                    'origin_country' => 'egypt',
                    'category' => 'production_services',
                    'group' => 'services',
                    'cost_as_inventory' => false,
                    'notes' => $this->note('Non-stock service line used in the mixed sales golden cycle.'),
                ],
                'pe_resin' => [
                    'name' => 'HDPE Blow Molding Grade - Runtime Demo',
                    'item_classification' => Product::ClassificationRawMaterial,
                    'barcode' => 'MGY-RM-HDPE-BLOW-001', 'reorder_point' => '2500.0000', 'unit' => 'kilogram',
                    'color' => 'transparent', 'origin_country' => 'egypt', 'category' => 'polymer_resins', 'group' => 'virgin_materials',
                    'notes' => $this->note('Virgin HDPE for secondary packaging production and procurement testing.'),
                ],
                'black_masterbatch' => [
                    'name' => 'Black Color Masterbatch - Runtime Demo',
                    'item_classification' => Product::ClassificationRawMaterial,
                    'barcode' => 'MGY-RM-MB-BLACK-001', 'reorder_point' => '100.0000', 'unit' => 'kilogram',
                    'origin_country' => 'egypt', 'category' => 'plastic_additives', 'group' => 'additives',
                    'notes' => $this->note('Black pigment concentrate for production formula testing.'),
                ],
                'white_masterbatch' => [
                    'name' => 'White Color Masterbatch - Runtime Demo',
                    'item_classification' => Product::ClassificationRawMaterial,
                    'barcode' => 'MGY-RM-MB-WHITE-001', 'reorder_point' => '100.0000', 'unit' => 'kilogram',
                    'origin_country' => 'egypt', 'category' => 'plastic_additives', 'group' => 'additives',
                    'notes' => $this->note('White pigment concentrate for food-container production.'),
                ],
                'printed_bag' => [
                    'name' => 'Printed Industrial Pail Bag - Runtime Demo',
                    'item_classification' => Product::ClassificationPackaging,
                    'barcode' => 'MGY-PK-BAG-PRINT-001', 'reorder_point' => '5000.0000', 'unit' => 'bag',
                    'origin_country' => 'egypt', 'category' => 'packing_materials', 'group' => 'packaging_consumables',
                    'notes' => $this->note('Printed protective bag used for customer-specific packing.'),
                ],
                'transparent_bag' => [
                    'name' => 'Transparent Food-Grade Bag - Runtime Demo',
                    'item_classification' => Product::ClassificationPackaging,
                    'barcode' => 'MGY-PK-BAG-CLEAR-001', 'reorder_point' => '10000.0000', 'unit' => 'bag',
                    'origin_country' => 'egypt', 'category' => 'packing_materials', 'group' => 'packaging_consumables',
                    'notes' => $this->note('Food-grade inner packing bag.'),
                ],
                'product_label' => [
                    'name' => 'Thermal Product Label Roll - Runtime Demo',
                    'item_classification' => Product::ClassificationPackaging,
                    'barcode' => 'MGY-PK-LABEL-ROLL-001', 'reorder_point' => '50.0000', 'unit' => 'roll',
                    'origin_country' => 'egypt', 'category' => 'packing_materials', 'group' => 'packaging_consumables',
                    'notes' => $this->note('Traceability label roll for batch and customer identification.'),
                ],
                'packing_tape' => [
                    'name' => '48mm Carton Packing Tape - Runtime Demo',
                    'item_classification' => Product::ClassificationPackaging,
                    'barcode' => 'MGY-PK-TAPE-48-001', 'reorder_point' => '100.0000', 'unit' => 'roll',
                    'origin_country' => 'egypt', 'category' => 'packing_materials', 'group' => 'packaging_consumables',
                    'notes' => $this->note('Carton sealing tape used by packing operations.'),
                ],
                'stretch_wrapper' => [
                    'name' => 'Pallet Stretch Wrapper Roll - Runtime Demo',
                    'item_classification' => Product::ClassificationPackaging,
                    'barcode' => 'MGY-PK-STRETCH-001', 'reorder_point' => '80.0000', 'unit' => 'roll',
                    'origin_country' => 'egypt', 'category' => 'packing_materials', 'group' => 'packaging_consumables',
                    'notes' => $this->note('Stretch film used for palletized finished-goods dispatch.'),
                ],
                'pail_lid' => [
                    'name' => '20L Tamper-Evident Blue Lid - Runtime Demo',
                    'item_classification' => Product::ClassificationFinishedProduct,
                    'barcode' => 'MGY-FG-LID-20L-BLU', 'reorder_point' => '750.0000', 'unit' => 'piece',
                    'size' => 'pail_20l', 'color' => 'royal_blue', 'model' => 'injection_pail',
                    'origin_country' => 'egypt', 'category' => 'industrial_pails', 'group' => 'plastic_finished_goods',
                    'notes' => $this->note('Separate saleable and production-consumable pail closure.'),
                ],
                'food_tub' => [
                    'name' => '1L Transparent Food Tub - Runtime Demo',
                    'item_classification' => Product::ClassificationFinishedProduct,
                    'barcode' => 'MGY-FG-FOOD-1L-CLR', 'reorder_point' => '1200.0000', 'unit' => 'piece',
                    'size' => 'container_5l', 'color' => 'transparent', 'model' => 'food_container',
                    'origin_country' => 'egypt', 'category' => 'food_packaging', 'group' => 'plastic_finished_goods',
                    'notes' => $this->note('Small food-safe tub for alternate BOM and sales testing.'),
                ],
                'maintenance_service' => [
                    'name' => 'Injection Machine Preventive Maintenance - Runtime Demo',
                    'item_classification' => Product::ClassificationService,
                    'barcode' => 'MGY-SVC-MAINT-IMM', 'reorder_point' => null, 'unit' => 'piece',
                    'origin_country' => 'egypt', 'category' => 'production_services', 'group' => 'services', 'cost_as_inventory' => false,
                    'notes' => $this->note('External maintenance service available for service procurement invoices.'),
                ],
                'transport_service' => [
                    'name' => 'Inbound Resin Transportation - Runtime Demo',
                    'item_classification' => Product::ClassificationService,
                    'barcode' => 'MGY-SVC-INBOUND-FREIGHT', 'reorder_point' => null, 'unit' => 'piece',
                    'origin_country' => 'egypt', 'category' => 'production_services', 'group' => 'services', 'cost_as_inventory' => false,
                    'notes' => $this->note('Inbound freight service for supplier and AP testing.'),
                ],
            ],
            'delta' => [
                'sofa_frame' => [
                    'name' => 'Tamper-Evident Plastic Lid - Runtime Demo',
                    'item_classification' => Product::ClassificationFinishedProduct,
                    'barcode' => 'DELTA-LID-TE-001',
                    'reorder_point' => '1000.0000',
                    'unit' => 'piece',
                    'size' => 'pail_20l',
                    'color' => 'royal_blue',
                    'model' => 'injection_pail',
                    'origin_country' => 'egypt',
                    'category' => 'industrial_pails',
                    'group' => 'plastic_finished_goods',
                    'notes' => $this->note('Finished closure for secondary-company scope checks.'),
                ],
                'bedside_table' => [
                    'name' => '1L Plastic Tub - Runtime Demo',
                    'item_classification' => Product::ClassificationFinishedProduct,
                    'barcode' => 'DELTA-TUB-1L-001',
                    'reorder_point' => '1500.0000',
                    'unit' => 'piece',
                    'size' => 'container_5l',
                    'color' => 'transparent',
                    'model' => 'food_container',
                    'origin_country' => 'egypt',
                    'category' => 'food_packaging',
                    'group' => 'plastic_finished_goods',
                    'notes' => $this->note('Small food tub for Delta product list checks.'),
                ],
                'linen_roll' => [
                    'name' => 'Recycled PP Regrind - Runtime Demo',
                    'item_classification' => Product::ClassificationRawMaterial,
                    'barcode' => 'DELTA-RM-RPP-001',
                    'reorder_point' => '1000.0000',
                    'unit' => 'kilogram',
                    'origin_country' => 'egypt',
                    'category' => 'polymer_resins',
                    'group' => 'raw_materials',
                    'notes' => $this->note('Recycled polymer material for secondary-company checks.'),
                ],
            ],
            'export' => [
                'wooden_crate' => [
                    'name' => 'Export Pallet Stretch Film - Runtime Demo',
                    'item_classification' => Product::ClassificationPackaging,
                    'barcode' => 'EXP-STRETCH-001',
                    'reorder_point' => '80.0000',
                    'unit' => 'kilogram',
                    'origin_country' => 'egypt',
                    'category' => 'packing_materials',
                    'group' => 'export_collection',
                    'notes' => $this->note('Export crate material for Damietta shipment testing.'),
                ],
                'hotel_room_set' => [
                    'name' => 'Export Food Container Set - Runtime Demo',
                    'item_classification' => Product::ClassificationFinishedProduct,
                    'barcode' => 'EXP-FOOD-SET-001',
                    'reorder_point' => '100.0000',
                    'unit' => 'set',
                    'size' => 'container_5l',
                    'color' => 'transparent',
                    'model' => 'food_container',
                    'origin_country' => 'egypt',
                    'category' => 'food_packaging',
                    'group' => 'export_collection',
                    'notes' => $this->note('Food-grade plastic container bundle for export company checks.'),
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
                'industrial_pail' => [
                    ['component' => 'pp_resin', 'quantity' => '0.7800', 'notes' => $this->note('Polypropylene resin required per completed pail and lid.')],
                    ['component' => 'blue_masterbatch', 'quantity' => '0.0200', 'notes' => $this->note('Blue masterbatch required per completed pail and lid.')],
                    ['component' => 'packing_carton', 'quantity' => '0.041667', 'notes' => $this->note('One packing carton per twenty-four pails.')],
                ],
                'food_container' => [
                    ['component' => 'pp_resin', 'quantity' => '0.1900', 'notes' => $this->note('Polypropylene resin required per food container.')],
                ],
                'pail_lid' => [
                    ['component' => 'pp_resin', 'quantity' => '0.1100', 'notes' => $this->note('Direct resin weight per tamper-evident lid.')],
                    ['component' => 'blue_masterbatch', 'quantity' => '0.0030', 'notes' => $this->note('Blue masterbatch blend per lid.')],
                ],
                'food_tub' => [
                    ['component' => 'pp_resin', 'quantity' => '0.0550', 'notes' => $this->note('Direct resin weight per one-liter food tub.')],
                    ['component' => 'transparent_bag', 'quantity' => '0.0100', 'notes' => $this->note('One inner bag per one hundred finished tubs.')],
                ],
            ],
            'delta' => [
                'sofa_frame' => [
                    ['component' => 'linen_roll', 'quantity' => '0.0550', 'notes' => $this->note('Recycled polypropylene required per lid.')],
                ],
            ],
            'export' => [
                'hotel_room_set' => [
                    ['component' => 'wooden_crate', 'quantity' => '0.2500', 'notes' => $this->note('Stretch film required per export container set.')],
                ],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function fullAdminPermissions(): array
    {
        return app(PermissionRegistryService::class)->all();
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
