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
        Schema::table('auth_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('auth_logs', 'login')) {
                $table->string('login')->nullable()->index();
            }

            if (! Schema::hasColumn('auth_logs', 'remember_me')) {
                $table->boolean('remember_me')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'context')) {
                $table->json('context')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('auth_logs', function (Blueprint $table): void {
            foreach (['login', 'remember_me', 'context'] as $column) {
                if (Schema::hasColumn('auth_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
