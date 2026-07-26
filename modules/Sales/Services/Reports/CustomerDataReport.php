<?php

namespace Modules\Sales\Services\Reports;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Services\Reports\BusinessPartnerDataReport;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCreditLimit;

class CustomerDataReport extends BusinessPartnerDataReport
{
    /**
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return Customer::class;
    }

    /**
     * @return class-string<Model>
     */
    protected function creditLimitModelClass(): string
    {
        return CustomerCreditLimit::class;
    }

    protected function creditLimitForeignKey(): string
    {
        return 'customer_id';
    }

    public function reportKey(): string
    {
        return 'customers';
    }

    public function partnerTranslationKey(): string
    {
        return 'customers';
    }

    public function routePrefix(): string
    {
        return 'admin.reports.customers';
    }

    public function permissionPrefix(): string
    {
        return 'reports.customers';
    }

    public function filenamePrefix(): string
    {
        return 'customers-report';
    }
}
