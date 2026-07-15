<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cash_vouchers', 'person_name')) {
            Schema::table('cash_vouchers', function (Blueprint $table): void {
                $table->string('person_name')->nullable()->after('amount_base');
            });
        }

        if (! Schema::hasColumn('cash_vouchers', 'person_national_id')) {
            Schema::table('cash_vouchers', function (Blueprint $table): void {
                $table->string('person_national_id', 50)->nullable()->after('person_name');
            });
        }

        if (! Schema::hasColumn('cash_vouchers', 'person_phone')) {
            Schema::table('cash_vouchers', function (Blueprint $table): void {
                $table->string('person_phone', 50)->nullable()->after('person_national_id');
            });
        }
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['person_phone', 'person_national_id', 'person_name'],
            fn (string $column): bool => Schema::hasColumn('cash_vouchers', $column),
        ));

        if ($columns !== []) {
            Schema::table('cash_vouchers', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
