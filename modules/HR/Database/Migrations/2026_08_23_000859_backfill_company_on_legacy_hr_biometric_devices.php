<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_biometric_devices')) {
            return;
        }

        DB::table('hr_biometric_devices')
            ->whereNull('company_id')
            ->whereNotNull('branch_id')
            ->update([
                'company_id' => DB::raw('(SELECT branches.company_id FROM branches WHERE branches.id = hr_biometric_devices.branch_id)'),
            ]);

        $singleCompanyIds = DB::table('companies')
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->limit(2)
            ->pluck('id');

        if ($singleCompanyIds->count() === 1) {
            DB::table('hr_biometric_devices')
                ->whereNull('company_id')
                ->update(['company_id' => $singleCompanyIds->first()]);
        }

        $seen = [];

        DB::table('hr_biometric_devices')
            ->whereNotNull('company_id')
            ->whereNotNull('device_uid')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'company_id', 'device_uid'])
            ->each(function (object $device) use (&$seen): void {
                $key = $device->company_id."\0".$device->device_uid;

                if (isset($seen[$key])) {
                    DB::table('hr_biometric_devices')->where('id', $device->id)->update(['device_uid' => null]);

                    return;
                }

                $seen[$key] = true;
            });

        DB::statement('CREATE UNIQUE INDEX hr_biometric_devices_company_device_uid_unique_active ON hr_biometric_devices (company_id, device_uid) WHERE deleted_at IS NULL AND device_uid IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS hr_biometric_devices_company_device_uid_unique_active');

        // Company assignment is retained because the former null value was invalid legacy data.
    }
};
