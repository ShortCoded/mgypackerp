<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Core\Models\FinancialPeriod;

class FinancialPeriodSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly OperatingScopeAccessService $scopeAccess,
    ) {}

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function paginated(Request $request): array
    {
        $search = $request->input('q', $request->input('term'));

        if ($this->shouldRestrictToOperatingScope($request)) {
            $user = $request->user();
            $query = $user instanceof User
                ? $this->scopeAccess->allowedFinancialPeriodQuery($user, $this->companyDocNums($request))
                : FinancialPeriod::query()
                    ->join('companies', 'companies.id', '=', 'financial_periods.company_id')
                    ->whereRaw('1 = 0');
        } else {
            $query = FinancialPeriod::query()
                ->leftJoin('companies', 'companies.id', '=', 'financial_periods.company_id')
                ->forCompany($this->companyContext->requireCompanyId($request))
                ->orderBy('financial_periods.from_date', 'desc')
                ->orderBy('financial_periods.doc_number', 'desc');
        }

        $query->select([
            'financial_periods.doc_num',
            'financial_periods.name',
            'financial_periods.doc_number',
            'financial_periods.is_closed',
            'companies.doc_num as company_doc_num',
        ]);

        if (! $this->shouldRestrictToOperatingScope($request) && $request->boolean('open_only')) {
            $query->open();
        }

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'financial_periods.doc_num',
                    'financial_periods.name',
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (FinancialPeriod $period): array => $this->item($period));
    }

    /**
     * @return array{id: string, text: string, company_doc_num: string|null}
     */
    public function item(FinancialPeriod $period): array
    {
        return [
            'id' => (string) $period->doc_num,
            'text' => $this->label($period),
            'company_doc_num' => $period->company_doc_num ?? $period->company?->doc_num,
        ];
    }

    public function label(FinancialPeriod $period): string
    {
        return trim(implode(' / ', array_filter([
            $period->name,
            $period->doc_num,
            $this->statusLabel($period),
        ])));
    }

    private function statusLabel(FinancialPeriod $period): string
    {
        return $period->is_closed
            ? __('financial_periods.statuses.closed')
            : __('financial_periods.statuses.open');
    }

    /**
     * @return list<string>
     */
    private function companyDocNums(Request $request): array
    {
        $value = $request->input('company_doc_nums', $request->input('company_doc_num', []));
        $values = is_array($value) ? $value : explode(',', (string) $value);

        return collect($values)
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->unique()
            ->values()
            ->all();
    }

    private function shouldRestrictToOperatingScope(Request $request): bool
    {
        return $request->string('access_scope')->trim()->toString() === 'operating_scope';
    }
}
