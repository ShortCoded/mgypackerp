<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_classifications', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable();
            $table->string('doc_num')->nullable();
            $table->string('code');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('account_type')->index();
            $table->string('statement_type')->index();
            $table->string('normal_balance')->index();
            $table->boolean('is_system')->default(true)->index();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable();
            $table->string('doc_num')->nullable();
            $table->string('account_code');
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->unsignedInteger('level')->default(1);
            $table->foreignId('account_classification_id')->nullable()->constrained('account_classifications')->nullOnDelete();
            $table->string('account_type')->index();
            $table->string('statement_type')->index();
            $table->string('normal_balance')->index();
            $table->boolean('is_group')->default(false)->index();
            $table->boolean('is_postable')->default(true)->index();
            $table->boolean('is_system')->default(false)->index();
            $table->string('status')->default('active')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('parent_id');
            $table->index('account_classification_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX account_classifications_code_unique_active ON account_classifications (code) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX account_classifications_doc_num_unique_active ON account_classifications (doc_num) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX account_classifications_doc_number_unique_active ON account_classifications (doc_number) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX accounts_doc_num_unique_active ON accounts (doc_num) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX accounts_doc_number_unique_active ON accounts (doc_number) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX accounts_account_code_unique_active ON accounts (account_code) WHERE deleted_at IS NULL');
        } else {
            Schema::table('account_classifications', function (Blueprint $table): void {
                $table->unique('code');
                $table->unique('doc_num');
                $table->unique('doc_number');
            });
            Schema::table('accounts', function (Blueprint $table): void {
                $table->unique('doc_num');
                $table->unique('doc_number');
                $table->unique('account_code');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('account_classifications');
    }
};
