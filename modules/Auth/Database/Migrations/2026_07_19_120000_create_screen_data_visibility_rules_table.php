<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screen_data_visibility_rules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->index();
            $table->string('doc_num')->index();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('screen_key', 160);
            $table->string('record_scope', 40)->default('own_records');
            $table->unsignedInteger('max_visible_records')->nullable();
            $table->unsignedInteger('duration_value')->nullable();
            $table->string('duration_unit', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['company_id', 'user_id', 'screen_key'], 'screen_visibility_rule_lookup');
            $table->index(['company_id', 'is_active', 'deleted_at'], 'screen_visibility_rule_effective');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            $grammar = DB::getQueryGrammar();
            $table = $grammar->wrapTable('screen_data_visibility_rules');
            $companyId = $grammar->wrap('company_id');
            $userId = $grammar->wrap('user_id');
            $screenKey = $grammar->wrap('screen_key');
            $isActive = $grammar->wrap('is_active');
            $deletedAt = $grammar->wrap('deleted_at');

            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('screen_visibility_rule_doc_number_unique_active')." ON {$table} ({$companyId}, ".$grammar->wrap('doc_number').") WHERE {$deletedAt} IS NULL");
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('screen_visibility_rule_doc_num_unique_active')." ON {$table} ({$companyId}, ".$grammar->wrap('doc_num').") WHERE {$deletedAt} IS NULL");
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('screen_visibility_rule_unique_active')." ON {$table} ({$companyId}, {$userId}, {$screenKey}) WHERE {$deletedAt} IS NULL AND {$isActive} = ".(DB::getDriverName() === 'pgsql' ? 'TRUE' : '1'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_data_visibility_rules');
    }
};
