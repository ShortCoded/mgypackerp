<?php

namespace Modules\Auth\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\BranchSelect2Service;
use Modules\Core\Services\CompanySelect2Service;
use Modules\Core\Services\FinancialPeriodSelect2Service;
use Modules\Core\Services\OperatingContextService;
use Throwable;

class DefaultLoginContextService
{
    public function __construct(
        private readonly OperatingContextService $operatingContext,
        private readonly CompanySelect2Service $companies,
        private readonly BranchSelect2Service $branches,
        private readonly FinancialPeriodSelect2Service $financialPeriods,
    ) {}

    /**
     * @return array{
     *     company: array{id: string, text: string}|null,
     *     branch: array{id: string, text: string, company_doc_num: string|null}|null,
     *     financial_period: array{id: string, text: string, company_doc_num: string|null}|null,
     *     has_saved: bool,
     *     is_invalid: bool
     * }
     */
    public function profileContext(User $user): array
    {
        $hasSaved = $this->hasAnySavedContext($user);
        $resolved = $this->resolveSavedContext($user);

        return [
            'company' => $resolved ? $this->companies->item($resolved['company']) : null,
            'branch' => $resolved ? $this->branches->item($resolved['branch']) : null,
            'financial_period' => $resolved ? $this->financialPeriods->item($resolved['financial_period']) : null,
            'has_saved' => $hasSaved,
            'is_invalid' => $hasSaved && $resolved === null,
        ];
    }

    /**
     * @param  array{company_doc_num: string, branch_doc_num: string, financial_period_doc_num: string}  $data
     * @return array{user: User, changed: bool, changed_fields: list<string>, context: array<string, mixed>}
     */
    public function update(User $user, array $data): array
    {
        $resolved = $this->operatingContext->resolveForUser(
            $user,
            $data['company_doc_num'],
            $data['branch_doc_num'],
            $data['financial_period_doc_num'],
        );

        return DB::transaction(function () use ($user, $resolved): array {
            $values = [
                'default_company_id' => $resolved['company']->getKey(),
                'default_branch_id' => $resolved['branch']->getKey(),
                'default_financial_period_id' => $resolved['financial_period']->getKey(),
            ];

            $changedFields = $this->changedFields($user, $values);

            if ($changedFields === []) {
                return [
                    'user' => $user->refresh(),
                    'changed' => false,
                    'changed_fields' => [],
                    'context' => $this->publicContext($resolved),
                ];
            }

            if (Schema::hasColumn($user->getTable(), 'updated_by')) {
                $values['updated_by'] = auth()->id();
            }

            $user->forceFill($values)->save();

            return [
                'user' => $user->refresh(),
                'changed' => true,
                'changed_fields' => $changedFields,
                'context' => $this->publicContext($resolved),
            ];
        });
    }

    /**
     * @return array{user: User, changed: bool, changed_fields: list<string>}
     */
    public function clear(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $values = [
                'default_company_id' => null,
                'default_branch_id' => null,
                'default_financial_period_id' => null,
            ];
            $changedFields = $this->changedFields($user, $values);

            if ($changedFields === []) {
                return [
                    'user' => $user->refresh(),
                    'changed' => false,
                    'changed_fields' => [],
                ];
            }

            if (Schema::hasColumn($user->getTable(), 'updated_by')) {
                $values['updated_by'] = auth()->id();
            }

            $user->forceFill($values)->save();

            return [
                'user' => $user->refresh(),
                'changed' => true,
                'changed_fields' => $changedFields,
            ];
        });
    }

    public function applyForLogin(Request $request, User $user): bool
    {
        $resolved = $this->resolveSavedContext($user);

        if ($resolved === null) {
            return false;
        }

        try {
            $this->operatingContext->select(
                $request,
                (string) $resolved['company']->doc_num,
                (string) $resolved['branch']->doc_num,
                (string) $resolved['financial_period']->doc_num,
            );

            return true;
        } catch (Throwable $exception) {
            report($exception);
            $this->operatingContext->clear($request);

            return false;
        }
    }

    /**
     * @return array{company: Company, branch: Branch, financial_period: FinancialPeriod}|null
     */
    private function resolveSavedContext(User $user): ?array
    {
        if (! $this->hasCompleteSavedContext($user)) {
            return null;
        }

        $company = Company::query()->whereKey((int) $user->default_company_id)->first();
        $branch = Branch::query()->whereKey((int) $user->default_branch_id)->first();
        $financialPeriod = FinancialPeriod::query()->whereKey((int) $user->default_financial_period_id)->first();

        if (! $company instanceof Company || ! $branch instanceof Branch || ! $financialPeriod instanceof FinancialPeriod) {
            return null;
        }

        try {
            return $this->operatingContext->resolveForUser(
                $user,
                (string) $company->doc_num,
                (string) $branch->doc_num,
                (string) $financialPeriod->doc_num,
            );
        } catch (ValidationException) {
            return null;
        }
    }

    private function hasCompleteSavedContext(User $user): bool
    {
        return is_numeric($user->default_company_id)
            && is_numeric($user->default_branch_id)
            && is_numeric($user->default_financial_period_id);
    }

    private function hasAnySavedContext(User $user): bool
    {
        return is_numeric($user->default_company_id)
            || is_numeric($user->default_branch_id)
            || is_numeric($user->default_financial_period_id);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function changedFields(User $user, array $values): array
    {
        $changedFields = [];

        foreach ($values as $field => $value) {
            $current = $user->{$field};

            if (($current === null ? null : (int) $current) !== ($value === null ? null : (int) $value)) {
                $changedFields[] = $field;
            }
        }

        return $changedFields;
    }

    /**
     * @param  array{company: Company, branch: Branch, financial_period: FinancialPeriod}  $resolved
     * @return array<string, mixed>
     */
    private function publicContext(array $resolved): array
    {
        return [
            'company' => $this->companies->item($resolved['company']),
            'branch' => $this->branches->item($resolved['branch']),
            'financial_period' => $this->financialPeriods->item($resolved['financial_period']),
        ];
    }
}
