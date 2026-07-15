<?php

namespace Modules\Core\Services;

use Illuminate\Http\Request;
use Modules\Core\Models\ItemUnit;

class ItemUnitSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function paginated(Request $request): array
    {
        $search = $request->input('q', $request->input('term'));

        $query = ItemUnit::query()
            ->active()
            ->forCompany($this->companyContext->requireCompanyId($request))
            ->select([
                'item_units.doc_num',
                'item_units.name',
                'item_units.doc_number',
            ])
            ->orderBy('item_units.name')
            ->orderBy('item_units.doc_number');

        $excludeDocNum = trim((string) $request->input('exclude_doc_num', ''));

        if ($excludeDocNum !== '') {
            $query->where('item_units.doc_num', '!=', $excludeDocNum);
        }

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'item_units.doc_num',
                    'item_units.name',
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (ItemUnit $unit): array => $this->item($unit));
    }

    /**
     * @return array{id: string, text: string}
     */
    public function item(ItemUnit $unit): array
    {
        return [
            'id' => (string) $unit->doc_num,
            'text' => $this->label($unit),
        ];
    }

    public function label(ItemUnit $unit): string
    {
        return trim(implode(' / ', array_filter([
            $unit->doc_num,
            $unit->name,
        ])));
    }
}
