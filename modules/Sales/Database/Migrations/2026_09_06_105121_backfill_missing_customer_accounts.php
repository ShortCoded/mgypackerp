<?php

use Illuminate\Database\Migrations\Migration;
use Modules\Accounting\Services\AccountCodeAllocator;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Sales\Models\Customer;

return new class extends Migration
{
    public function up(): void
    {
        $accountCodes = app(AccountCodeAllocator::class);
        $partnerAccounts = app(BusinessPartnerAccountService::class);

        Customer::query()
            ->whereNull('account_id')
            ->orderBy('id')
            ->chunkById(100, function ($customers) use ($accountCodes, $partnerAccounts): void {
                foreach ($customers as $customer) {
                    $accountCodes->transaction(function () use ($customer, $partnerAccounts): void {
                        $lockedCustomer = Customer::query()->lockForUpdate()->find($customer->getKey());

                        if (! $lockedCustomer instanceof Customer || $lockedCustomer->account_id !== null) {
                            return;
                        }

                        $parent = $partnerAccounts->rootAccountForCompany(
                            BusinessPartnerAccountService::Customer,
                            (int) $lockedCustomer->company_id,
                        );
                        $linkedAccount = $partnerAccounts->createOrUpdateLinkedAccount(
                            BusinessPartnerAccountService::Customer,
                            null,
                            $parent,
                            [
                                'name' => $lockedCustomer->name,
                                'status' => $lockedCustomer->status,
                                'notes' => $lockedCustomer->notes,
                            ],
                        )['account'];

                        $lockedCustomer->forceFill([
                            'account_id' => $linkedAccount->getKey(),
                            'account_group_id' => null,
                        ])->saveQuietly();
                    });
                }
            });
    }

    public function down(): void {}
};
