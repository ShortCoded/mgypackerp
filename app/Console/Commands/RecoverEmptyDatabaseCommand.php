<?php

namespace App\Console\Commands;

use Database\Seeders\EmergencyRecoverySeeder;
use Illuminate\Console\Command;
use Spatie\Permission\PermissionRegistrar;

class RecoverEmptyDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:recover-empty-db';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely seed the minimum ERP bootstrap data for an empty migrated database.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->warn('This recovery command never truncates or deletes data.');

        $this->call('optimize:clear');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->call('db:seed', [
            '--class' => EmergencyRecoverySeeder::class,
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->newLine();
        $this->info('Recovery seeder completed.');

        return self::SUCCESS;
    }
}
