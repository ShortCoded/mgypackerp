<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seeded_reference_records')) {
            return;
        }

        Schema::create('seeded_reference_records', function (Blueprint $table): void {
            $table->id();
            $table->string('seed_key');
            $table->string('table_name');
            $table->unsignedBigInteger('record_id');
            $table->string('record_name')->nullable();
            $table->timestamps();

            $table->index('seed_key');
            $table->unique(['seed_key', 'table_name', 'record_id'], 'seed_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seeded_reference_records');
    }
};
