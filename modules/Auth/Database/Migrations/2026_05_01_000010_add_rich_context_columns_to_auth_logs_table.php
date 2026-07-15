<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $columns = [
        'session_fingerprint',
        'request_id',
        'route_name',
        'path',
        'referrer',
        'accept_language',
        'locale',
        'timezone',
        'browser_name',
        'browser_version',
        'os_name',
        'os_version',
        'device_type',
        'platform',
        'is_mobile',
        'is_tablet',
        'is_desktop',
        'is_bot',
        'client_ip',
        'country',
        'region',
        'city',
        'latitude',
        'longitude',
        'location_accuracy',
        'geo_source',
        'isp',
        'asn',
        'client_context',
        'device_context',
        'location_context',
        'network_context',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('auth_logs')) {
            return;
        }

        Schema::table('auth_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('auth_logs', 'session_fingerprint')) {
                $table->string('session_fingerprint')->nullable()->index();
            }

            if (! Schema::hasColumn('auth_logs', 'request_id')) {
                $table->string('request_id')->nullable()->index();
            }

            if (! Schema::hasColumn('auth_logs', 'route_name')) {
                $table->string('route_name')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'path')) {
                $table->string('path')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'referrer')) {
                $table->text('referrer')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'accept_language')) {
                $table->string('accept_language')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'locale')) {
                $table->string('locale', 20)->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'timezone')) {
                $table->string('timezone')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'browser_name')) {
                $table->string('browser_name')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'browser_version')) {
                $table->string('browser_version')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'os_name')) {
                $table->string('os_name')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'os_version')) {
                $table->string('os_version')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'device_type')) {
                $table->string('device_type')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'platform')) {
                $table->string('platform')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'is_mobile')) {
                $table->boolean('is_mobile')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'is_tablet')) {
                $table->boolean('is_tablet')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'is_desktop')) {
                $table->boolean('is_desktop')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'is_bot')) {
                $table->boolean('is_bot')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'client_ip')) {
                $table->string('client_ip', 45)->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'country')) {
                $table->string('country')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'region')) {
                $table->string('region')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'city')) {
                $table->string('city')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'location_accuracy')) {
                $table->decimal('location_accuracy', 10, 2)->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'geo_source')) {
                $table->string('geo_source')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'isp')) {
                $table->string('isp')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'asn')) {
                $table->string('asn')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'client_context')) {
                $table->jsonb('client_context')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'device_context')) {
                $table->jsonb('device_context')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'location_context')) {
                $table->jsonb('location_context')->nullable();
            }

            if (! Schema::hasColumn('auth_logs', 'network_context')) {
                $table->jsonb('network_context')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('auth_logs')) {
            return;
        }

        Schema::table('auth_logs', function (Blueprint $table): void {
            foreach ($this->columns as $column) {
                if (Schema::hasColumn('auth_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
