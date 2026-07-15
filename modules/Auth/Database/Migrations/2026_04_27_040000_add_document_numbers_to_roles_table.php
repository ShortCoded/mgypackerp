<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        Schema::table($table, function (Blueprint $table): void {
            if (! Schema::hasColumn($table->getTable(), 'doc_number')) {
                $table->unsignedBigInteger('doc_number')->nullable();
            }

            if (! Schema::hasColumn($table->getTable(), 'doc_num')) {
                $table->string('doc_num')->nullable();
            }
        });

        $this->backfill($table);

        $this->createUniqueIndex($table, 'doc_number', 'roles_doc_number_unique');
        $this->createUniqueIndex($table, 'doc_num', 'roles_doc_num_unique');
    }

    public function down(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        Schema::table($table, function (Blueprint $table): void {
            $table->dropUnique('roles_doc_number_unique');
            $table->dropUnique('roles_doc_num_unique');

            if (Schema::hasColumn($table->getTable(), 'doc_number')) {
                $table->dropColumn('doc_number');
            }

            if (Schema::hasColumn($table->getTable(), 'doc_num')) {
                $table->dropColumn('doc_num');
            }
        });
    }

    private function backfill(string $table): void
    {
        $config = config('document_numbers.roles', []);
        $prefix = (string) ($config['prefix'] ?? 'Role-');
        $padding = (int) ($config['padding'] ?? 5);
        $nextNumber = ((int) DB::table($table)->max('doc_number')) + 1;

        DB::table($table)
            ->orderBy('id')
            ->where(function ($query): void {
                $query->whereNull('doc_number')
                    ->orWhereNull('doc_num');
            })
            ->chunkById(100, function ($roles) use ($table, $prefix, $padding, &$nextNumber): void {
                foreach ($roles as $role) {
                    $docNumber = $role->doc_number ?: $nextNumber++;
                    $docNum = $role->doc_num ?: $prefix.str_pad((string) $docNumber, $padding, '0', STR_PAD_LEFT);

                    DB::table($table)
                        ->where('id', $role->id)
                        ->update([
                            'doc_number' => $docNumber,
                            'doc_num' => $docNum,
                        ]);
                }
            });
    }

    private function createUniqueIndex(string $table, string $column, string $index): void
    {
        $wrappedTable = DB::getQueryGrammar()->wrapTable($table);
        $wrappedIndex = DB::getQueryGrammar()->wrap($index);
        $wrappedColumn = DB::getQueryGrammar()->wrap($column);

        try {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn})");
        } catch (Throwable $exception) {
            report($exception);
        }
    }
};
