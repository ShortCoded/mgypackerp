<?php

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DocumentNumberService;

class DefaultOperatingContextSeeder extends Seeder
{
    private const CompanyName = 'Short Coded';

    private const BranchName = 'Main Branch';

    private const BranchType = 'administrative';

    public function run(): void
    {
        DB::transaction(function (): void {
            $company = $this->seedCompany();

            $this->seedBranch($company);
            $this->seedFinancialPeriod($company);
        });
    }

    private function seedCompany(): Company
    {
        $company = Company::withTrashed()
            ->where('name', self::CompanyName)
            ->first();

        if ($company instanceof Company) {
            if ($company->trashed()) {
                $company->restore();
            }

            $hasOtherActiveMain = Company::query()
                ->main()
                ->whereKeyNot($company->getKey())
                ->exists();

            $company->forceFill([
                'name' => $company->name ?: self::CompanyName,
                'legal_name' => $company->legal_name ?: self::CompanyName,
                'status' => 'active',
                'country' => $company->country ?: 'Egypt',
                'is_main' => ! $hasOtherActiveMain,
                'notes' => $company->notes ?: 'Seeded default company for fresh ERP setup.',
            ])->save();

            $this->ensureDocumentNumber($company, 'companies');

            return $company->refresh();
        }

        /** @var Company $company */
        $hasActiveMain = Company::query()->main()->exists();
        $company = Company::query()->create([
            ...app(DocumentNumberService::class)->next('companies', Company::class),
            'name' => self::CompanyName,
            'legal_name' => self::CompanyName,
            'status' => 'active',
            'is_main' => ! $hasActiveMain,
            'country' => 'Egypt',
            'notes' => 'Seeded default company for fresh ERP setup.',
        ]);

        return $company->refresh();
    }

    private function seedBranch(Company $company): Branch
    {
        $branch = Branch::withTrashed()
            ->where('company_id', $company->getKey())
            ->where('name', self::BranchName)
            ->first();

        if ($branch instanceof Branch) {
            if ($branch->trashed()) {
                $branch->restore();
            }

            $branch->forceFill([
                'company_id' => $company->getKey(),
                'name' => $branch->name ?: self::BranchName,
                'type' => $branch->type ?: self::BranchType,
                'status' => 'active',
                'notes' => $branch->notes ?: 'Seeded default branch for fresh ERP setup.',
            ])->save();

            $this->ensureDocumentNumber($branch, 'branches');

            return $branch->refresh();
        }

        /** @var Branch $branch */
        $branch = Branch::query()->create([
            ...app(DocumentNumberService::class)->next('branches', Branch::class),
            'company_id' => $company->getKey(),
            'name' => self::BranchName,
            'type' => self::BranchType,
            'status' => 'active',
            'notes' => 'Seeded default branch for fresh ERP setup.',
        ]);

        return $branch->refresh();
    }

    private function seedFinancialPeriod(Company $company): FinancialPeriod
    {
        $year = Carbon::now()->year;
        $name = (string) $year;
        $fromDate = Carbon::create($year, 1, 1)->toDateString();
        $toDate = Carbon::create($year, 12, 31)->toDateString();

        $period = FinancialPeriod::withTrashed()
            ->where('name', $name)
            ->where(function ($query) use ($company): void {
                $query->where('company_id', $company->getKey())
                    ->orWhereNull('company_id');
            })
            ->orderByRaw('CASE WHEN company_id = ? THEN 0 ELSE 1 END', [$company->getKey()])
            ->first();

        if ($period instanceof FinancialPeriod) {
            if ($period->trashed()) {
                $period->restore();
            }

            $values = [
                'company_id' => $company->getKey(),
                'name' => $period->name ?: $name,
                'from_date' => $period->from_date ?: $fromDate,
                'to_date' => $period->to_date ?: $toDate,
                'is_closed' => false,
                'notes' => $period->notes ?: 'Seeded default financial period for fresh ERP setup.',
            ];

            if (Schema::hasColumn('financial_periods', 'allows_opening_entries')) {
                $values['allows_opening_entries'] = true;
            }

            $period->forceFill($values)->save();

            $this->ensureDocumentNumber($period, 'financial_periods', $company->getKey());

            return $period->refresh();
        }

        $values = [
            ...app(DocumentNumberService::class)->nextForCompany('financial_periods', FinancialPeriod::class, $company->getKey()),
            'company_id' => $company->getKey(),
            'name' => $name,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'is_closed' => false,
            'notes' => 'Seeded default financial period for fresh ERP setup.',
        ];

        if (Schema::hasColumn('financial_periods', 'allows_opening_entries')) {
            $values['allows_opening_entries'] = true;
        }

        /** @var FinancialPeriod $period */
        $period = FinancialPeriod::query()->create($values);

        return $period->refresh();
    }

    private function ensureDocumentNumber(Model $record, string $key, ?int $companyId = null): void
    {
        if ($record->getAttribute('doc_number') !== null && $record->getAttribute('doc_num') !== null) {
            return;
        }

        $documentNumber = $companyId === null
            ? app(DocumentNumberService::class)->next($key, $record::class)
            : app(DocumentNumberService::class)->nextForCompany($key, $record::class, $companyId);

        $record->forceFill($documentNumber)->save();
    }
}
