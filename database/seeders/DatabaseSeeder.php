<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Accounting\Database\Seeders\BaselineChartOfAccountsSeeder;
use Modules\Accounting\Database\Seeders\BaselineCostCentersSeeder;
use Modules\Auth\Database\Seeders\PermissionSeeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Default seeding is baseline-only and production-friendly.
        // Demo/manual seeders must be run explicitly with db:seed --class=...
        $this->call(PermissionSeeder::class);
        $this->call(DefaultOperatingContextSeeder::class);
        $this->call(DefaultAdminSeeder::class);
        $this->call(BaselineChartOfAccountsSeeder::class);
        $this->call(BaselineCostCentersSeeder::class);
    }
}
