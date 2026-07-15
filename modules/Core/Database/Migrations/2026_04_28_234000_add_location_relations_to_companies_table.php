<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $relations = [
        'country_id' => 'hr_countries',
        'governorate_id' => 'hr_governorates',
        'city_id' => 'hr_cities',
        'area_id' => 'hr_areas',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            foreach ($this->relations as $column => $referencedTable) {
                if (Schema::hasColumn('companies', $column)) {
                    continue;
                }

                if (Schema::hasTable($referencedTable)) {
                    $table->foreignId($column)->nullable()->constrained($referencedTable)->nullOnDelete();

                    continue;
                }

                $table->unsignedBigInteger($column)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            foreach (array_keys($this->relations) as $column) {
                if (! Schema::hasColumn('companies', $column)) {
                    continue;
                }

                if (DB::getDriverName() !== 'sqlite') {
                    try {
                        $table->dropForeign([$column]);
                    } catch (Throwable) {
                    }
                }

                $table->dropColumn($column);
            }
        });
    }
};
