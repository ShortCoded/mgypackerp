<?php

namespace App\Console\Commands;

use App\Services\PlasticFactoryDemoVerifier;
use Database\Seeders\FixedAssetsProcurementClientDemoSeeder;
use Illuminate\Console\Command;

class ErpDemoDataCommand extends Command
{
    protected $signature = 'erp:client-demo-data {--verify : Verify the persistent Fixed Assets and Procurement client-demo dataset without modifying it}';

    protected $description = 'Create or verify persistent Fixed Assets and Procurement client-demo data';

    public function handle(PlasticFactoryDemoVerifier $verifier): int
    {
        if (! in_array(app()->environment(), $this->allowedEnvironments(), true)) {
            $this->components->error('Client demo data is disabled outside local, development, and testing environments.');

            return self::FAILURE;
        }

        if (! $this->option('verify')) {
            $exitCode = $this->call('db:seed', [
                '--class' => FixedAssetsProcurementClientDemoSeeder::class,
                '--force' => true,
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
            $this->components->error('Fixed Assets and Procurement client-demo verification failed.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Client demo data is ready: %d assets, %d suppliers, %d purchase orders, %d goods receipts, and %s EGP outstanding for Supplier B.',
            $result['counts']['assets'],
            $result['counts']['suppliers'],
            $result['counts']['purchase_orders'],
            $result['counts']['goods_receipts'],
            $result['evidence']['supplier_b_outstanding'],
        ));

        return self::SUCCESS;
    }

    /** @return list<string> */
    public function allowedEnvironments(): array
    {
        return ['local', 'development', 'testing'];
    }
}
