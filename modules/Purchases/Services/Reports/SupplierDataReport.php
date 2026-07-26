<?php

namespace Modules\Purchases\Services\Reports;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\Reports\BusinessPartnerDataReport;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierCreditLimit;

class SupplierDataReport extends BusinessPartnerDataReport
{
    /**
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return Supplier::class;
    }

    /**
     * @return class-string<Model>
     */
    protected function creditLimitModelClass(): string
    {
        return SupplierCreditLimit::class;
    }

    protected function creditLimitForeignKey(): string
    {
        return 'supplier_id';
    }

    public function reportKey(): string
    {
        return 'suppliers';
    }

    public function partnerTranslationKey(): string
    {
        return 'suppliers';
    }

    public function routePrefix(): string
    {
        return 'admin.reports.suppliers';
    }

    public function permissionPrefix(): string
    {
        return 'reports.suppliers';
    }

    public function filenamePrefix(): string
    {
        return 'suppliers-report';
    }
}
