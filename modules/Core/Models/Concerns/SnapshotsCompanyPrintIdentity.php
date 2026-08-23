<?php

namespace Modules\Core\Models\Concerns;

use Modules\Core\Models\Company;
use Modules\Core\Services\CompanyPrintIdentityService;

trait SnapshotsCompanyPrintIdentity
{
    protected static function bootSnapshotsCompanyPrintIdentity(): void
    {
        static::creating(function ($document): void {
            if ($document->print_identity_snapshot !== null || ! $document->company_id) {
                return;
            }

            $company = Company::query()->find($document->company_id);
            if ($company instanceof Company) {
                $document->print_identity_snapshot = app(CompanyPrintIdentityService::class)->forCompany($company);
            }
        });
    }
}
