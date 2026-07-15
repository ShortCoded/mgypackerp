<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_employees', 'emergency_contact_name')) {
                $table->string('emergency_contact_name')->nullable();
            }

            if (! Schema::hasColumn('hr_employees', 'emergency_contact_phone')) {
                $table->string('emergency_contact_phone', 50)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['emergency_contact_name', 'emergency_contact_phone'],
                fn (string $column): bool => Schema::hasColumn('hr_employees', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
