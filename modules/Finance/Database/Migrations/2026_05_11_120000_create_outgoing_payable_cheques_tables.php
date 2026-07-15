<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outgoing_payable_cheques', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('doc_number')->nullable()->index();
            $table->string('doc_num')->nullable()->index();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();
            $table->string('payee_name');
            $table->decimal('amount', 15, 2);
            $table->date('cheque_date');
            $table->text('memo')->nullable();
            $table->string('status')->default('draft')->index();
            $table->unsignedBigInteger('cheque_serial')->nullable()->index();
            $table->uuid('print_session_token')->nullable()->unique();
            $table->timestamp('print_session_expires_at')->nullable();
            $table->unsignedSmallInteger('print_pdf_version')->default(0);
            $table->timestamp('last_pdf_generated_at')->nullable();
            $table->string('replaces_doc_num')->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();
        });

        Schema::create('outgoing_payable_cheque_print_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outgoing_payable_cheque_id')->constrained('outgoing_payable_cheques')->cascadeOnDelete();
            $table->string('event');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            $grammar = DB::getQueryGrammar();
            $table = $grammar->wrapTable('outgoing_payable_cheques');
            $deletedAt = $grammar->wrap('deleted_at');
            foreach (['doc_number', 'doc_num'] as $column) {
                $index = $grammar->wrap('outgoing_payable_cheques_'.$column.'_unique_active');
                $wrappedColumn = $grammar->wrap($column);
                DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$table} ({$wrappedColumn}) WHERE {$deletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL");
            }
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS '.$grammar->wrap('outgoing_payable_cheques_bank_serial_unique_active').'
                ON '.$table.' ('.$grammar->wrap('bank_account_id').', '.$grammar->wrap('cheque_serial').')
                WHERE '.$deletedAt.' IS NULL AND '.$grammar->wrap('cheque_serial').' IS NOT NULL');
        } else {
            Schema::table('outgoing_payable_cheques', function (Blueprint $table): void {
                $table->unique(['bank_account_id', 'cheque_serial']);
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            $grammar = DB::getQueryGrammar();
            foreach ([
                'outgoing_payable_cheques_doc_number_unique_active',
                'outgoing_payable_cheques_doc_num_unique_active',
                'outgoing_payable_cheques_bank_serial_unique_active',
            ] as $index) {
                DB::statement('DROP INDEX IF EXISTS '.$grammar->wrap($index));
            }
        }

        Schema::dropIfExists('outgoing_payable_cheque_print_logs');
        Schema::dropIfExists('outgoing_payable_cheques');
    }
};
