<?php

namespace App\Console\Commands;

use App\Services\IntegratedPlasticFactoryDemoVerifier;
use Database\Seeders\RuntimeDemoDataSeeder;
use Illuminate\Console\Command;

class PlasticFactoryDemoDataCommand extends Command
{
    protected $signature = 'erp:demo-data {--verify : Verify the persistent plastic-factory dataset without modifying it}';

    protected $description = 'Create or verify the persistent, integrated plastic-factory development dataset';

    public function handle(IntegratedPlasticFactoryDemoVerifier $verifier): int
    {
        if (! app()->environment(['local', 'development', 'testing'])
            && ! filter_var((string) env('ALLOW_RUNTIME_DEMO_DATA', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->components->error('Demo data is disabled outside local, development, and testing environments.');

            return self::FAILURE;
        }

        if (! $this->option('verify')) {
            $exitCode = $this->call('db:seed', [
                '--class' => RuntimeDemoDataSeeder::class,
                '--no-interaction' => true,
            ]);
            if ($exitCode !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $result = $verifier->verify();
        foreach ($result['checks'] as $name => $passed) {
            $this->line(sprintf('%s %s', $passed ? '[PASS]' : '[FAIL]', str_replace('_', ' ', $name)));
        }

        if (! $result['ok']) {
            $this->components->error('Plastic-factory demo data verification failed.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Company', $result['company']);
        $this->components->twoColumnDetail('Financial period', $result['period']);
        $this->table(
            ['Persistent record type', 'Count'],
            collect($result['counts'])->map(fn (int $count, string $name): array => [str_replace('_', ' ', $name), $count])->values()->all(),
        );
        $this->table(
            ['Supplier scenario', 'Invoices', 'Paid', 'Returns/debits', 'Outstanding', 'Next due'],
            collect($result['supplier_balances'])->map(fn (array $balance, string $label): array => [
                $label.' — '.$balance['supplier'],
                $balance['invoices'],
                $balance['paid'],
                $balance['returns'],
                $balance['outstanding'],
                $balance['next_due_date'] ?? '—',
            ])->values()->all(),
        );
        $this->table(
            ['Inventory control', 'Subledger', 'General ledger', 'Difference', 'Status'],
            collect($result['inventory_reconciliation'])->map(fn (array $row): array => [
                $row['label'],
                $row['subledger'],
                $row['gl'],
                $row['difference'],
                $row['status'],
            ])->all(),
        );
        $this->table(
            ['Persisted chain', 'Document numbers / identifiers'],
            collect($result['documents'])->map(fn (array $documents, string $name): array => [
                str_replace('_', ' ', $name),
                implode(', ', $documents),
            ])->values()->all(),
        );
        $this->components->info('Persistent plastic-factory development data is ready; no demo or user business records were cleaned.');

        return self::SUCCESS;
    }
}
