<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addLocationHierarchy();
        $this->addPartnerLocationColumns('customers');
        $this->addPartnerLocationColumns('suppliers');
        $this->backfillPartnerLocations('customers');
        $this->backfillPartnerLocations('suppliers');
        $this->createCreditLimitTable('customer_credit_limits', 'customer_id', 'customers');
        $this->createCreditLimitTable('supplier_credit_limits', 'supplier_id', 'suppliers');
        $this->createCreditLimitIndexes('customer_credit_limits', 'customer_id');
        $this->createCreditLimitIndexes('supplier_credit_limits', 'supplier_id');
    }

    public function down(): void
    {
        $this->dropCreditLimitIndexes('supplier_credit_limits');
        $this->dropCreditLimitIndexes('customer_credit_limits');
        Schema::dropIfExists('supplier_credit_limits');
        Schema::dropIfExists('customer_credit_limits');
        $this->dropPartnerLocationColumns('suppliers');
        $this->dropPartnerLocationColumns('customers');
        $this->dropLocationHierarchy();
    }

    private function addLocationHierarchy(): void
    {
        $relations = [
            'hr_governorates' => ['country_id' => 'hr_countries'],
            'hr_cities' => ['governorate_id' => 'hr_governorates'],
            'hr_areas' => ['city_id' => 'hr_cities'],
        ];

        foreach ($relations as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                foreach ($columns as $column => $referencedTable) {
                    if (Schema::hasColumn($tableName, $column)) {
                        continue;
                    }

                    $table->foreignId($column)->nullable()->constrained($referencedTable)->nullOnDelete();
                }
            });
        }
    }

    private function addPartnerLocationColumns(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $relations = [
            'country_id' => 'hr_countries',
            'governorate_id' => 'hr_governorates',
            'city_id' => 'hr_cities',
            'area_id' => 'hr_areas',
        ];

        Schema::table($tableName, function (Blueprint $table) use ($tableName, $relations): void {
            foreach ($relations as $column => $referencedTable) {
                if (Schema::hasColumn($tableName, $column)) {
                    continue;
                }

                $table->foreignId($column)->nullable()->constrained($referencedTable)->nullOnDelete();
            }
        });
    }

    private function backfillPartnerLocations(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        foreach ([
            'country' => ['column' => 'country_id', 'table' => 'hr_countries'],
            'governorate' => ['column' => 'governorate_id', 'table' => 'hr_governorates'],
            'city' => ['column' => 'city_id', 'table' => 'hr_cities'],
        ] as $legacyColumn => $location) {
            if (! Schema::hasColumn($tableName, $legacyColumn) || ! Schema::hasColumn($tableName, $location['column']) || ! Schema::hasTable($location['table'])) {
                continue;
            }

            DB::statement(sprintf(
                'UPDATE %s SET %s = locations.id FROM %s AS locations WHERE %s.%s IS NULL AND %s.%s IS NOT NULL AND LOWER(TRIM(%s.%s)) = LOWER(TRIM(locations.name)) AND locations.deleted_at IS NULL',
                DB::getQueryGrammar()->wrapTable($tableName),
                DB::getQueryGrammar()->wrap($location['column']),
                DB::getQueryGrammar()->wrapTable($location['table']),
                DB::getQueryGrammar()->wrapTable($tableName),
                DB::getQueryGrammar()->wrap($location['column']),
                DB::getQueryGrammar()->wrapTable($tableName),
                DB::getQueryGrammar()->wrap($legacyColumn),
                DB::getQueryGrammar()->wrapTable($tableName),
                DB::getQueryGrammar()->wrap($legacyColumn),
            ));
        }
    }

    private function createCreditLimitTable(string $tableName, string $partnerColumn, string $partnerTable): void
    {
        if (Schema::hasTable($tableName)) {
            return;
        }

        Schema::create($tableName, function (Blueprint $table) use ($partnerColumn, $partnerTable): void {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId($partnerColumn)->nullable()->constrained($partnerTable)->cascadeOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('credit_limit', 18, 4)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['company_id', $partnerColumn]);
            $table->index(['company_id', 'currency_id']);
        });
    }

    private function createCreditLimitIndexes(string $tableName, string $partnerColumn): void
    {
        if (! Schema::hasTable($tableName) || ! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable($tableName);
        $index = $grammar->wrap("{$tableName}_partner_currency_unique_active");
        $companyId = $grammar->wrap('company_id');
        $partner = $grammar->wrap($partnerColumn);
        $currency = $grammar->wrap('currency_id');
        $deletedAt = $grammar->wrap('deleted_at');

        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$table} ({$companyId}, {$partner}, {$currency}) WHERE {$deletedAt} IS NULL AND {$partner} IS NOT NULL");
    }

    private function dropCreditLimitIndexes(string $tableName): void
    {
        if (! in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.DB::getQueryGrammar()->wrap("{$tableName}_partner_currency_unique_active"));
    }

    private function dropPartnerLocationColumns(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
            foreach (['area_id', 'city_id', 'governorate_id', 'country_id'] as $column) {
                if (! Schema::hasColumn($tableName, $column)) {
                    continue;
                }

                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign([$column]);
                }

                $table->dropColumn($column);
            }
        });
    }

    private function dropLocationHierarchy(): void
    {
        foreach ([
            'hr_areas' => ['city_id'],
            'hr_cities' => ['governorate_id'],
            'hr_governorates' => ['country_id'],
        ] as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName, $columns): void {
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($tableName, $column)) {
                        continue;
                    }

                    if (DB::getDriverName() !== 'sqlite') {
                        $table->dropForeign([$column]);
                    }

                    $table->dropColumn($column);
                }
            });
        }
    }
};
