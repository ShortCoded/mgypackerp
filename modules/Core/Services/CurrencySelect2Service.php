<?php

namespace Modules\Core\Services;

use Illuminate\Http\Request;
use Modules\Core\Models\Currency;

class CurrencySelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function paginated(Request $request): array
    {
        $query = Currency::query()
            ->active()
            ->select(['doc_num', 'doc_number', 'name', 'code'])
            ->orderByDesc('is_main')
            ->orderBy('code');

        $this->companies->applyCompanyScope($query, 'currencies', $request);

        $terms = $this->searchService->terms($request->input('q', $request->input('term')));
        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, ['text' => ['doc_num', 'name', 'code']]);
        }

        return $this->select2->paginated($query, $request, fn (Currency $currency): array => $this->item($currency));
    }

    public function item(Currency $currency): array
    {
        return [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' — ', array_filter([$currency->code, $currency->name]))),
            'is_main' => (bool) $currency->is_main,
        ];
    }
}
