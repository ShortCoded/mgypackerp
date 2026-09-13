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
        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->foreignId('user_id')
                ->nullable()
                ->after('company_id')
                ->unique()
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->decimal('attendance_latitude', 10, 7)->nullable()->after('address');
            $table->decimal('attendance_longitude', 10, 7)->nullable()->after('attendance_latitude');
            $table->unsignedInteger('attendance_radius_meters')->default(200)->after('attendance_longitude');
            $table->unsignedInteger('attendance_max_accuracy_meters')->default(100)->after('attendance_radius_meters');
            $table->string('attendance_location_policy', 20)->default('warn')->after('attendance_max_accuracy_meters');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn([
                'attendance_latitude',
                'attendance_longitude',
                'attendance_radius_meters',
                'attendance_max_accuracy_meters',
                'attendance_location_policy',
            ]);
        });

        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
