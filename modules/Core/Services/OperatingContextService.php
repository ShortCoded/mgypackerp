<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;

class OperatingContextService
{
    public const CompanyIdKey = 'current_company_id';

    public const CompanyDocNumKey = 'current_company_doc_num';

    public const BranchIdKey = 'current_branch_id';

    public const BranchDocNumKey = 'current_branch_doc_num';

    public const FinancialPeriodIdKey = 'current_financial_period_id';

    public const FinancialPeriodDocNumKey = 'current_financial_period_doc_num';

    public function __construct(
        private readonly RequestMemo $memo,
        private readonly OperatingScopeAccessService $scopeAccess,
    ) {}

    /**
     * @return array{
     *     company: array{id: string, doc_num: string, name: string, label: string}|null,
     *     branch: array{id: string, doc_num: string, name: string, label: string, company_doc_num: string|null}|null,
     *     financial_period: array{id: string, doc_num: string, name: string, label: string}|null,
     *     requires_selection: bool
     * }
     */
    public function current(Request $request): array
    {
        $user = $request->user();
        $hadContextBeforeAutoSelection = $user ? $this->hasAnyContextSession($request) : false;
        $this->autoSelectIfOnlyOneValidContext($request);

        $company = $user ? $this->selectedCompany($request, $user) : null;
        $branch = $user ? $this->selectedBranch($request, $user, $company) : null;
        $financialPeriod = $user ? $this->selectedFinancialPeriod($request, $user, $company) : null;

        if ($user && (! $company || ! $branch || ! $financialPeriod)) {
            $shouldRetryAutoSelection = $hadContextBeforeAutoSelection;

            if ($this->hasAnyContextSession($request)) {
                $this->clear($request);
                $shouldRetryAutoSelection = true;
            }

            $company = null;
            $branch = null;
            $financialPeriod = null;

            if ($shouldRetryAutoSelection) {
                $this->autoSelectIfOnlyOneValidContext($request);
                $company = $this->selectedCompany($request, $user);
                $branch = $this->selectedBranch($request, $user, $company);
                $financialPeriod = $this->selectedFinancialPeriod($request, $user, $company);
            }
        }

        return [
            'company' => $company ? $this->companyPayload($company) : null,
            'branch' => $branch ? $this->branchPayload($branch) : null,
            'financial_period' => $financialPeriod ? $this->financialPeriodPayload($financialPeriod) : null,
            'requires_selection' => ! $company || ! $branch || ! $financialPeriod,
        ];
    }

    /**
     * @return array{
     *     company_id: int|null,
     *     company_doc_num: string|null,
     *     company_name: string|null,
     *     branch_id: int|null,
     *     branch_doc_num: string|null,
     *     branch_name: string|null,
     *     financial_period_id: int|null,
     *     financial_period_doc_num: string|null,
     *     financial_period_name: string|null
     * }
     */
    public function snapshot(Request $request): array
    {
        $user = $request->user();
        $company = $user instanceof User ? $this->selectedCompany($request, $user) : null;
        $branch = $user instanceof User ? $this->selectedBranch($request, $user, $company) : null;
        $financialPeriod = $user instanceof User ? $this->selectedFinancialPeriod($request, $user, $company) : null;

        return [
            'company_id' => $company ? (int) $company->getKey() : null,
            'company_doc_num' => $company?->doc_num,
            'company_name' => $company?->name,
            'branch_id' => $branch ? (int) $branch->getKey() : null,
            'branch_doc_num' => $branch?->doc_num,
            'branch_name' => $branch?->name,
            'financial_period_id' => $financialPeriod ? (int) $financialPeriod->getKey() : null,
            'financial_period_doc_num' => $financialPeriod?->doc_num,
            'financial_period_name' => $financialPeriod?->name,
        ];
    }

    public function currentCompanyModel(Request $request): ?Company
    {
        $user = $request->user();
        $this->autoSelectIfOnlyOneValidContext($request);

        return $user instanceof User ? $this->selectedCompany($request, $user) : null;
    }

    public function selectedCompanyId(Request $request): ?int
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $company = $this->selectedCompany($request, $user);

        return $company instanceof Company ? (int) $company->getKey() : null;
    }

    /**
     * @return Builder<Branch>
     */
    public function allowedBranchQueryForCurrentCompany(Request $request): Builder
    {
        $user = $request->user();
        $company = $this->currentCompanyModel($request);

        if (! $user instanceof User || ! $company instanceof Company) {
            return Branch::query()->whereRaw('1 = 0');
        }

        return $this->allowedBranchQuery($user, $company);
    }

    public function allowedBranchForCurrentCompany(Request $request, string $branchDocNum): ?Branch
    {
        return $this->allowedBranchQueryForCurrentCompany($request)
            ->where('branches.doc_num', $branchDocNum)
            ->first();
    }

    /**
     * @return array{company: Company, branch: Branch, financial_period: FinancialPeriod}
     */
    public function resolveForUser(User $user, string $companyDocNum, string $branchDocNum, string $financialPeriodDocNum): array
    {
        $company = $this->allowedCompanyQuery($user)
            ->where('companies.doc_num', $companyDocNum)
            ->first();

        $branch = $company
            ? $this->allowedBranchQuery($user, $company)
                ->where('branches.doc_num', $branchDocNum)
                ->first()
            : null;

        $financialPeriod = $company
            ? $this->allowedFinancialPeriodQuery($user, $company)
                ->where('financial_periods.doc_num', $financialPeriodDocNum)
                ->first()
            : null;

        $errors = [];

        if (! $company) {
            $errors['company_doc_num'] = __('operating_context.validation.company_invalid');
        }

        if (! $branch) {
            $errors['branch_doc_num'] = $company
                ? __('operating_context.validation.branch_invalid')
                : __('operating_context.validation.branch_company_required');
        }

        if (! $financialPeriod) {
            $errors['financial_period_doc_num'] = __('operating_context.validation.financial_period_invalid');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'company' => $company,
            'branch' => $branch,
            'financial_period' => $financialPeriod,
        ];
    }

    /**
     * @return array{
     *     companies: list<array{id: string, text: string, name: string, doc_num: string}>,
     *     branches: list<array{id: string, text: string, name: string, doc_num: string, company_doc_num: string|null}>,
     *     financial_periods: list<array{id: string, text: string, name: string, doc_num: string}>,
     *     current: array<string, mixed>,
     *     auto_select: array{
     *         company: array{id: string, text: string, name: string, doc_num: string}|null,
     *         branch: array{id: string, text: string, name: string, doc_num: string, company_doc_num: string|null}|null,
     *         financial_period: array{id: string, text: string, name: string, doc_num: string}|null
     *     },
     *     option_counts: array{companies: int, branches: int, financial_periods: int}
     * }
     */
    public function options(Request $request): array
    {
        $user = $request->user();
        $current = $this->current($request);
        $requestedCompanyDocNum = $request->string('company_doc_num')->trim()->toString();
        $companies = $user ? $this->companyOptions($user) : [];
        $autoCompany = null;
        $companyDocNum = null;

        if ($user instanceof User && $requestedCompanyDocNum !== '') {
            $requestedCompany = $this->allowedCompanyQuery($user)->where('companies.doc_num', $requestedCompanyDocNum)->first();
            $autoCompany = $requestedCompany instanceof Company ? $this->companyOption($requestedCompany) : null;
            $companyDocNum = $autoCompany['doc_num'] ?? null;
        } elseif (is_array($current['company'] ?? null)) {
            $autoCompany = $this->companyOptionFromPayload($current['company']);
            $companyDocNum = $autoCompany['doc_num'] ?? null;
        } elseif (count($companies) === 1) {
            $autoCompany = $companies[0];
            $companyDocNum = $autoCompany['doc_num'];
        }

        $selectedCompany = $user && $companyDocNum
            ? $this->allowedCompanyQuery($user)->where('companies.doc_num', $companyDocNum)->first()
            : null;
        $currentForOptions = $requestedCompanyDocNum !== ''
            ? $this->currentForRequestedCompany($current, $selectedCompany, $requestedCompanyDocNum)
            : $current;
        $branches = $user && $selectedCompany ? $this->branchOptions($user, $companyDocNum) : [];
        $financialPeriods = $user && $selectedCompany ? $this->financialPeriodOptions($user, $selectedCompany) : [];

        return [
            'companies' => $companies,
            'branches' => $branches,
            'financial_periods' => $financialPeriods,
            'current' => $currentForOptions,
            'auto_select' => [
                'company' => $autoCompany,
                'branch' => $this->autoBranchOption($currentForOptions, $branches, $companyDocNum),
                'financial_period' => $this->autoFinancialPeriodOption($currentForOptions, $financialPeriods, $companyDocNum),
            ],
            'option_counts' => [
                'companies' => count($companies),
                'branches' => count($branches),
                'financial_periods' => count($financialPeriods),
            ],
        ];
    }

    /**
     * @return array{
     *     company: array{id: string, doc_num: string, name: string, label: string},
     *     branch: array{id: string, doc_num: string, name: string, label: string, company_doc_num: string|null},
     *     financial_period: array{id: string, doc_num: string, name: string, label: string},
     *     requires_selection: false
     * }
     */
    public function select(Request $request, string $companyDocNum, string $branchDocNum, string $financialPeriodDocNum): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $resolved = $this->resolveForUser($user, $companyDocNum, $branchDocNum, $financialPeriodDocNum);
        $company = $resolved['company'];
        $branch = $resolved['branch'];
        $financialPeriod = $resolved['financial_period'];

        $request->session()->put([
            self::CompanyIdKey => $company->getKey(),
            self::CompanyDocNumKey => $company->doc_num,
            self::BranchIdKey => $branch->getKey(),
            self::BranchDocNumKey => $branch->doc_num,
            self::FinancialPeriodIdKey => $financialPeriod->getKey(),
            self::FinancialPeriodDocNumKey => $financialPeriod->doc_num,
        ]);

        return [
            'company' => $this->companyPayload($company),
            'branch' => $this->branchPayload($branch),
            'financial_period' => $this->financialPeriodPayload($financialPeriod),
            'requires_selection' => false,
        ];
    }

    public function clear(Request $request): void
    {
        $request->session()->forget($this->sessionKeys());
    }

    /**
     * @return list<string>
     */
    public function sessionKeys(): array
    {
        return [
            self::CompanyIdKey,
            self::CompanyDocNumKey,
            self::BranchIdKey,
            self::BranchDocNumKey,
            self::FinancialPeriodIdKey,
            self::FinancialPeriodDocNumKey,
        ];
    }

    private function selectedCompany(Request $request, User $user): ?Company
    {
        $id = $request->session()->get(self::CompanyIdKey);
        $docNum = $request->session()->get(self::CompanyDocNumKey);

        if (! is_numeric($id) || ! is_string($docNum) || $docNum === '') {
            $request->session()->forget([self::CompanyIdKey, self::CompanyDocNumKey, self::BranchIdKey, self::BranchDocNumKey]);

            return null;
        }

        $company = $this->memo->remember(
            "operating_context.selected_company.{$user->getKey()}.{$id}.{$docNum}",
            fn (): ?Company => $this->allowedCompanyQuery($user)
                ->where('companies.id', (int) $id)
                ->where('companies.doc_num', $docNum)
                ->first()
        );

        if (! $company) {
            $request->session()->forget($this->sessionKeys());
        }

        return $company;
    }

    private function selectedBranch(Request $request, User $user, ?Company $company): ?Branch
    {
        $id = $request->session()->get(self::BranchIdKey);
        $docNum = $request->session()->get(self::BranchDocNumKey);

        if (! $company || ! is_numeric($id) || ! is_string($docNum) || $docNum === '') {
            $request->session()->forget([self::BranchIdKey, self::BranchDocNumKey]);

            return null;
        }

        $branch = $this->memo->remember(
            "operating_context.selected_branch.{$user->getKey()}.{$company->getKey()}.{$id}.{$docNum}",
            fn (): ?Branch => $this->allowedBranchQuery($user, $company)
                ->without('company')
                ->where('branches.id', (int) $id)
                ->where('branches.doc_num', $docNum)
                ->first()
        );

        if (! $branch) {
            $request->session()->forget([self::BranchIdKey, self::BranchDocNumKey]);
        } else {
            $branch->setRelation('company', $company);
        }

        return $branch;
    }

    private function selectedFinancialPeriod(Request $request, User $user, ?Company $company): ?FinancialPeriod
    {
        $id = $request->session()->get(self::FinancialPeriodIdKey);
        $docNum = $request->session()->get(self::FinancialPeriodDocNumKey);

        if (! $company || ! is_numeric($id) || ! is_string($docNum) || $docNum === '') {
            $request->session()->forget([self::FinancialPeriodIdKey, self::FinancialPeriodDocNumKey]);

            return null;
        }

        $financialPeriod = $this->memo->remember(
            "operating_context.selected_financial_period.{$user->getKey()}.{$company->getKey()}.{$id}.{$docNum}",
            fn (): ?FinancialPeriod => $this->allowedFinancialPeriodQuery($user, $company)
                ->where('financial_periods.id', (int) $id)
                ->where('financial_periods.doc_num', $docNum)
                ->first()
        );

        if (! $financialPeriod) {
            $request->session()->forget([self::FinancialPeriodIdKey, self::FinancialPeriodDocNumKey]);
        }

        return $financialPeriod;
    }

    /**
     * @return Builder<Company>
     */
    private function allowedCompanyQuery(User $user): Builder
    {
        return $this->scopeAccess->allowedCompanyQuery($user);
    }

    /**
     * @return Builder<Branch>
     */
    private function allowedBranchQuery(User $user, ?Company $company = null): Builder
    {
        return $this->scopeAccess->allowedBranchQuery(
            $user,
            $company instanceof Company ? [(string) $company->doc_num] : null,
        );
    }

    /**
     * @return Builder<FinancialPeriod>
     */
    private function allowedFinancialPeriodQuery(User $user, ?Company $company = null): Builder
    {
        return $this->scopeAccess->allowedFinancialPeriodQuery(
            $user,
            $company instanceof Company ? [(string) $company->doc_num] : null,
        );
    }

    /**
     * @return list<array{id: string, text: string, name: string, doc_num: string}>
     */
    private function companyOptions(User $user): array
    {
        return $this->allowedCompanyQuery($user)
            ->limit(250)
            ->get()
            ->map(fn (Company $company): array => $this->companyOption($company))
            ->values()
            ->all();
    }

    /**
     * @return array{id: string, text: string, name: string, doc_num: string}
     */
    private function companyOption(Company $company): array
    {
        return [
            'id' => (string) $company->doc_num,
            'text' => $this->companyLabel($company),
            'name' => (string) $company->name,
            'doc_num' => (string) $company->doc_num,
        ];
    }

    /**
     * @return list<array{id: string, text: string, name: string, doc_num: string, company_doc_num: string|null}>
     */
    private function branchOptions(User $user, ?string $companyDocNum = null): array
    {
        $company = $companyDocNum ? $this->allowedCompanyQuery($user)->where('companies.doc_num', $companyDocNum)->first() : null;

        if ($companyDocNum && ! $company) {
            return [];
        }

        return $this->allowedBranchQuery($user, $company)
            ->limit(250)
            ->get()
            ->map(fn (Branch $branch): array => [
                'id' => (string) $branch->doc_num,
                'text' => $this->branchLabel($branch),
                'name' => (string) $branch->name,
                'doc_num' => (string) $branch->doc_num,
                'company_doc_num' => $branch->company?->doc_num,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, text: string, name: string, doc_num: string}>
     */
    private function financialPeriodOptions(User $user, ?Company $company = null): array
    {
        return $this->allowedFinancialPeriodQuery($user, $company)
            ->limit(250)
            ->get()
            ->map(fn (FinancialPeriod $period): array => [
                'id' => (string) $period->doc_num,
                'text' => $this->financialPeriodLabel($period),
                'name' => (string) $period->name,
                'doc_num' => (string) $period->doc_num,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function currentForRequestedCompany(array $current, ?Company $company, string $requestedCompanyDocNum): array
    {
        if (! $company instanceof Company) {
            return [
                'company' => null,
                'branch' => null,
                'financial_period' => null,
                'requires_selection' => true,
            ];
        }

        if (($current['company']['doc_num'] ?? null) !== $requestedCompanyDocNum) {
            return [
                'company' => $this->companyPayload($company),
                'branch' => null,
                'financial_period' => null,
                'requires_selection' => true,
            ];
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, text: string, name: string, doc_num: string}|null
     */
    private function companyOptionFromPayload(array $payload): ?array
    {
        $docNum = trim((string) ($payload['doc_num'] ?? $payload['id'] ?? ''));

        if ($docNum === '') {
            return null;
        }

        return [
            'id' => $docNum,
            'text' => (string) ($payload['label'] ?? $payload['text'] ?? $payload['name'] ?? $docNum),
            'name' => (string) ($payload['name'] ?? $docNum),
            'doc_num' => $docNum,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  list<array{id: string, text: string, name: string, doc_num: string, company_doc_num: string|null}>  $branches
     * @return array{id: string, text: string, name: string, doc_num: string, company_doc_num: string|null}|null
     */
    private function autoBranchOption(array $current, array $branches, ?string $companyDocNum): ?array
    {
        if (! $companyDocNum) {
            return null;
        }

        $currentBranch = $current['branch'] ?? null;

        if (is_array($currentBranch) && ($currentBranch['company_doc_num'] ?? $companyDocNum) === $companyDocNum) {
            return [
                'id' => (string) ($currentBranch['doc_num'] ?? $currentBranch['id'] ?? ''),
                'text' => (string) ($currentBranch['label'] ?? $currentBranch['text'] ?? $currentBranch['name'] ?? $currentBranch['doc_num'] ?? ''),
                'name' => (string) ($currentBranch['name'] ?? $currentBranch['doc_num'] ?? ''),
                'doc_num' => (string) ($currentBranch['doc_num'] ?? $currentBranch['id'] ?? ''),
                'company_doc_num' => (string) ($currentBranch['company_doc_num'] ?? $companyDocNum),
            ];
        }

        return count($branches) === 1 ? $branches[0] : null;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  list<array{id: string, text: string, name: string, doc_num: string}>  $financialPeriods
     * @return array{id: string, text: string, name: string, doc_num: string}|null
     */
    private function autoFinancialPeriodOption(array $current, array $financialPeriods, ?string $companyDocNum): ?array
    {
        if (! $companyDocNum) {
            return null;
        }

        $currentPeriod = $current['financial_period'] ?? null;

        if (is_array($currentPeriod) && $currentPeriod !== []) {
            return [
                'id' => (string) ($currentPeriod['doc_num'] ?? $currentPeriod['id'] ?? ''),
                'text' => (string) ($currentPeriod['label'] ?? $currentPeriod['text'] ?? $currentPeriod['name'] ?? $currentPeriod['doc_num'] ?? ''),
                'name' => (string) ($currentPeriod['name'] ?? $currentPeriod['doc_num'] ?? ''),
                'doc_num' => (string) ($currentPeriod['doc_num'] ?? $currentPeriod['id'] ?? ''),
            ];
        }

        return count($financialPeriods) === 1 ? $financialPeriods[0] : null;
    }

    /**
     * @return array{id: string, doc_num: string, name: string, label: string}
     */
    private function companyPayload(Company $company): array
    {
        return [
            'id' => (string) $company->doc_num,
            'doc_num' => (string) $company->doc_num,
            'name' => (string) $company->name,
            'label' => $this->companyLabel($company),
        ];
    }

    /**
     * @return array{id: string, doc_num: string, name: string, label: string, company_doc_num: string|null}
     */
    private function branchPayload(Branch $branch): array
    {
        return [
            'id' => (string) $branch->doc_num,
            'doc_num' => (string) $branch->doc_num,
            'name' => (string) $branch->name,
            'label' => $this->branchLabel($branch),
            'company_doc_num' => $branch->company?->doc_num,
        ];
    }

    /**
     * @return array{id: string, doc_num: string, name: string, label: string}
     */
    private function financialPeriodPayload(FinancialPeriod $period): array
    {
        return [
            'id' => (string) $period->doc_num,
            'doc_num' => (string) $period->doc_num,
            'name' => (string) $period->name,
            'label' => $this->financialPeriodLabel($period),
        ];
    }

    private function companyLabel(Company $company): string
    {
        return trim(implode(' / ', array_filter([
            $company->name,
            $company->doc_num,
        ])));
    }

    private function branchLabel(Branch $branch): string
    {
        return trim(implode(' / ', array_filter([
            $branch->name,
            $branch->doc_num,
            $branch->company?->name,
            $this->branchTypeLabel($branch),
        ])));
    }

    private function financialPeriodLabel(FinancialPeriod $period): string
    {
        return trim(implode(' / ', array_filter([
            $period->name,
            $period->doc_num,
            $this->financialPeriodStatusLabel($period),
        ])));
    }

    private function branchTypeLabel(Branch $branch): ?string
    {
        if (! is_string($branch->type) || trim($branch->type) === '') {
            return null;
        }

        $key = "branches.types.{$branch->type}";
        $label = __($key);

        return $label === $key ? $branch->type : $label;
    }

    private function financialPeriodStatusLabel(FinancialPeriod $period): string
    {
        return $period->is_closed
            ? __('financial_periods.statuses.closed')
            : __('financial_periods.statuses.open');
    }

    private function autoSelectIfOnlyOneValidContext(Request $request): void
    {
        $user = $request->user();

        if (! $user instanceof User
            || $request->session()->has(self::CompanyIdKey)
            || $request->session()->has(self::BranchIdKey)
            || $request->session()->has(self::FinancialPeriodIdKey)) {
            return;
        }

        $companies = $this->allowedCompanyQuery($user)->limit(2)->get();

        if ($companies->count() !== 1) {
            return;
        }

        /** @var Company $company */
        $company = $companies->first();
        $branches = $this->allowedBranchQuery($user, $company)->limit(2)->get();
        $periods = $this->allowedFinancialPeriodQuery($user, $company)->limit(2)->get();

        if ($branches->count() !== 1 || $periods->count() !== 1) {
            return;
        }

        /** @var Branch $branch */
        $branch = $branches->first();
        /** @var FinancialPeriod $period */
        $period = $periods->first();

        $request->session()->put([
            self::CompanyIdKey => $company->getKey(),
            self::CompanyDocNumKey => $company->doc_num,
            self::BranchIdKey => $branch->getKey(),
            self::BranchDocNumKey => $branch->doc_num,
            self::FinancialPeriodIdKey => $period->getKey(),
            self::FinancialPeriodDocNumKey => $period->doc_num,
        ]);
    }

    private function hasAnyContextSession(Request $request): bool
    {
        foreach ($this->sessionKeys() as $key) {
            if ($request->session()->has($key)) {
                return true;
            }
        }

        return false;
    }
}
