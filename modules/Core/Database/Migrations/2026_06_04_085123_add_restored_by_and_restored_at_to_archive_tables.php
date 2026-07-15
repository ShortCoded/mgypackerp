<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('archive_files')) {
            Schema::table('archive_files', function (Blueprint $table): void {
                if (! Schema::hasColumn('archive_files', 'restored_by')) {
                    $this->restoredByColumn($table);
                }

                if (! Schema::hasColumn('archive_files', 'restored_at')) {
                    $table->timestamp('restored_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('archive_folders')) {
            Schema::table('archive_folders', function (Blueprint $table): void {
                if (! Schema::hasColumn('archive_folders', 'restored_by')) {
                    $this->restoredByColumn($table);
                }

                if (! Schema::hasColumn('archive_folders', 'restored_at')) {
                    $table->timestamp('restored_at')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        //
    }

    private function restoredByColumn(Blueprint $table): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $table->unsignedBigInteger('restored_by')->nullable()->index();

            return;
        }

        $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
    }
};
