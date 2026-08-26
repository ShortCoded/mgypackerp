<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\Company;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $foundation = app(BusinessPartnerAccountService::class);

        Company::query()
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (int $companyId) => $foundation->ensureFixedAssetBaselineForCompany($companyId));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The system groups may already be referenced by production fixed assets.
    }
};
