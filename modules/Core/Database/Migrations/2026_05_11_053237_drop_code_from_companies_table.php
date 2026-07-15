<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'code')) {
            return;
        }

        $this->dropIndexIfExists('companies_code_unique_active');
        $this->dropIndexIfExists('companies_code_index');

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies') || Schema::hasColumn('companies', 'code')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table): void {
            $table->string('code', 100)->nullable()->after('commercial_name')->index();
        });
    }

    private function dropIndexIfExists(string $index): void
    {
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);

        DB::statement("DROP INDEX IF EXISTS {$wrappedIndex}");
    }
};
