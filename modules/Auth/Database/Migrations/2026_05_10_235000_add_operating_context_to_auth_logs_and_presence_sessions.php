<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'auth_logs',
        'user_presence_sessions',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            $this->addOperatingContextColumns($tableName);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            $this->dropOperatingContextColumns($tableName);
        }
    }

    private function addOperatingContextColumns(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'branch_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('branch_doc_num')->nullable()->index();
            $table->string('branch_name')->nullable();
            $table->unsignedBigInteger('financial_period_id')->nullable()->index();
            $table->string('financial_period_doc_num')->nullable()->index();
            $table->string('financial_period_name')->nullable();
        });
    }

    private function dropOperatingContextColumns(string $tableName): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'branch_id')) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->dropColumn([
                'branch_id',
                'branch_doc_num',
                'branch_name',
                'financial_period_id',
                'financial_period_doc_num',
                'financial_period_name',
            ]);
        });
    }
};
