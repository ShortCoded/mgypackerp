<?php

namespace Modules\Auth\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Validation\Validator;
use Modules\Core\Services\OperatingScopeAccessService;

trait ValidatesRoleOperatingScope
{
    private function validateOperatingScope(Validator $validator): void
    {
        if (! $this->canManageOperatingScope()) {
            return;
        }

        $user = $this->user();

        if (! $user instanceof User) {
            return;
        }

        $companyDocNums = $this->normalizeDocNums($this->input('accessible_company_doc_nums', []));
        $branchDocNums = $this->normalizeDocNums($this->input('accessible_branch_doc_nums', []));
        $periodDocNums = $this->normalizeDocNums($this->input('accessible_financial_period_doc_nums', []));
        $scopeAccess = app(OperatingScopeAccessService::class);

        $this->validateAssignableCompanies($validator, $scopeAccess, $user, $companyDocNums);
        $this->validateAssignableBranches($validator, $scopeAccess, $user, $companyDocNums, $branchDocNums);
        $this->validateAssignableFinancialPeriods($validator, $scopeAccess, $user, $companyDocNums, $periodDocNums);
    }

    /**
     * @param  list<string>  $companyDocNums
     */
    private function validateAssignableCompanies(
        Validator $validator,
        OperatingScopeAccessService $scopeAccess,
        User $user,
        array $companyDocNums
    ): void {
        if ($companyDocNums === []) {
            if (! $scopeAccess->hasUnrestrictedCompanyAccess($user)) {
                $validator->errors()->add('accessible_company_doc_nums', __('roles.operating_scope.company_unavailable'));
            }

            return;
        }

        $assignableDocNums = $scopeAccess->allowedCompanyQuery($user)
            ->whereIn('companies.doc_num', $companyDocNums)
            ->pluck('companies.doc_num')
            ->all();

        if (array_diff($companyDocNums, $assignableDocNums) !== []) {
            $validator->errors()->add('accessible_company_doc_nums', __('roles.operating_scope.company_unavailable'));
        }
    }

    /**
     * @param  list<string>  $companyDocNums
     * @param  list<string>  $branchDocNums
     */
    private function validateAssignableBranches(
        Validator $validator,
        OperatingScopeAccessService $scopeAccess,
        User $user,
        array $companyDocNums,
        array $branchDocNums
    ): void {
        if ($branchDocNums === []) {
            if (! $scopeAccess->hasUnrestrictedBranchAccess($user)) {
                $validator->errors()->add('accessible_branch_doc_nums', __('roles.operating_scope.branch_company_or_unavailable'));
            }

            return;
        }

        if ($companyDocNums === []) {
            $validator->errors()->add('accessible_branch_doc_nums', __('roles.operating_scope.branch_company_or_unavailable'));

            return;
        }

        $assignableDocNums = $scopeAccess->allowedBranchQuery($user, $companyDocNums)
            ->whereIn('branches.doc_num', $branchDocNums)
            ->pluck('branches.doc_num')
            ->all();

        if (array_diff($branchDocNums, $assignableDocNums) !== []) {
            $validator->errors()->add('accessible_branch_doc_nums', __('roles.operating_scope.branch_company_or_unavailable'));
        }
    }

    /**
     * @param  list<string>  $companyDocNums
     * @param  list<string>  $periodDocNums
     */
    private function validateAssignableFinancialPeriods(
        Validator $validator,
        OperatingScopeAccessService $scopeAccess,
        User $user,
        array $companyDocNums,
        array $periodDocNums
    ): void {
        if ($periodDocNums === []) {
            if (! $scopeAccess->hasUnrestrictedFinancialPeriodAccess($user)) {
                $validator->errors()->add('accessible_financial_period_doc_nums', __('roles.operating_scope.financial_period_company_or_unavailable'));
            }

            return;
        }

        if ($companyDocNums === []) {
            $validator->errors()->add('accessible_financial_period_doc_nums', __('roles.operating_scope.financial_period_company_or_unavailable'));

            return;
        }

        $assignableDocNums = $scopeAccess->allowedFinancialPeriodQuery($user, $companyDocNums)
            ->whereIn('financial_periods.doc_num', $periodDocNums)
            ->pluck('financial_periods.doc_num')
            ->all();

        if (array_diff($periodDocNums, $assignableDocNums) !== []) {
            $validator->errors()->add('accessible_financial_period_doc_nums', __('roles.operating_scope.financial_period_company_or_unavailable'));
        }
    }
}
