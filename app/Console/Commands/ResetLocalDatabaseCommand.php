<?php

namespace App\Console\Commands;

use Database\Seeders\EmergencyRecoverySeeder;
use Database\Seeders\RuntimeDemoDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Spatie\Permission\PermissionRegistrar;

class ResetLocalDatabaseCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:reset-local-db
        {--demo : Seed runtime demo data after the login-ready baseline}
        {--force : Skip interactive confirmation for local automation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Safely wipe and reseed a local ERP database.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $environment = app()->environment();
        $connection = (string) config('database.default');
        $database = $this->currentDatabaseName($connection);

        if ($environment === 'production') {
            $this->error('Refusing to reset the database in production.');

            return self::FAILURE;
        }

        if (! $this->isAllowedEnvironment($environment)) {
            $this->error(sprintf(
                'Refusing to reset the database in APP_ENV [%s]. Allowed environments: %s.',
                $environment,
                implode(', ', $this->allowedEnvironments()),
            ));

            return self::FAILURE;
        }

        if ($database === '') {
            $this->error('Refusing to reset the database because the configured database name is empty.');

            return self::FAILURE;
        }

        if ($this->looksDangerous($connection) || $this->looksDangerous($database)) {
            $this->error(sprintf(
                'Refusing to reset a database connection/name that looks production-like. Connection: [%s], database: [%s].',
                $connection,
                $database,
            ));

            return self::FAILURE;
        }

        $this->printDestructiveWarning($environment, $connection, $database);

        if (! $this->option('force') && ! $this->confirm('Do you understand that all database data will be deleted?', false)) {
            $this->warn('Database reset cancelled.');

            return self::FAILURE;
        }

        if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
            $this->error('Database reset failed while running migrations.');

            return self::FAILURE;
        }

        $this->forgetPermissionCache();

        foreach ($this->seederClasses((bool) $this->option('demo')) as $seederClass) {
            if ($this->call('db:seed', ['--class' => $seederClass, '--force' => true]) !== self::SUCCESS) {
                $this->error(sprintf('Database reset failed while running [%s].', $seederClass));

                return self::FAILURE;
            }

            $this->forgetPermissionCache();
        }

        $this->printCompletionSummary((bool) $this->option('demo'));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    public function allowedEnvironments(): array
    {
        return [
            'local',
            'development',
            'testing',
        ];
    }

    public function isAllowedEnvironment(string $environment): bool
    {
        return in_array($environment, $this->allowedEnvironments(), true);
    }

    public function looksDangerous(string $value): bool
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return true;
        }

        foreach (['production', 'prod', 'live', 'real', 'client', 'customer', 'tenant'] as $term) {
            if (str_contains($value, $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<class-string>
     */
    public function seederClasses(bool $includeDemo): array
    {
        $seeders = [
            PermissionSeeder::class,
            EmergencyRecoverySeeder::class,
        ];

        if ($includeDemo) {
            $seeders[] = RuntimeDemoDataSeeder::class;
        }

        return $seeders;
    }

    private function currentDatabaseName(string $connection): string
    {
        $database = Config::get("database.connections.{$connection}.database");

        return is_string($database) ? trim($database) : '';
    }

    private function printDestructiveWarning(string $environment, string $connection, string $database): void
    {
        $this->alert('DESTRUCTIVE LOCAL DATABASE RESET');
        $this->warn('All database data will be deleted before the ERP is reseeded.');
        $this->line('APP_ENV: '.$environment);
        $this->line('DB connection: '.$connection);
        $this->line('DB database: '.$database);
        $this->line('Demo data: '.($this->option('demo') ? 'yes' : 'no'));
        $this->newLine();
    }

    private function forgetPermissionCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function printCompletionSummary(bool $demoSeeded): void
    {
        $company = Company::query()
            ->where('name', 'Short Coded')
            ->first();
        $branch = $company instanceof Company
            ? Branch::query()
                ->where('company_id', $company->getKey())
                ->where('name', 'Main Branch')
                ->first()
            : null;
        $period = $company instanceof Company
            ? FinancialPeriod::query()
                ->where('company_id', $company->getKey())
                ->where('name', now()->year)
                ->first()
            : null;

        $this->newLine();
        $this->info('Local ERP database reset completed.');
        $this->line('Default login: admin@erp.local');
        $this->line('Default password: password');
        $this->line('Company: '.$this->summaryLabel($company, 'Short Coded'));
        $this->line('Branch: '.$this->summaryLabel($branch, 'Main Branch'));
        $this->line('Financial period: '.$this->summaryLabel($period, (string) now()->year));
        $this->line('Runtime demo data seeded: '.($demoSeeded ? 'yes' : 'no'));
        $this->warn('Reminder: change the default password if this database will be shared.');
    }

    private function summaryLabel(Company|Branch|FinancialPeriod|null $model, string $fallback): string
    {
        if ($model === null) {
            return $fallback.' (not found)';
        }

        return sprintf('%s [%s]', $model->name, $model->doc_num);
    }
}
