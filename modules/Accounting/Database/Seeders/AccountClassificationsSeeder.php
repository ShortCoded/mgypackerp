<?php

namespace Modules\Accounting\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Accounting\Services\AccountClassificationRegistry;

class AccountClassificationsSeeder extends Seeder
{
    public function run(): void
    {
        app(AccountClassificationRegistry::class)->synchronize();
    }
}
