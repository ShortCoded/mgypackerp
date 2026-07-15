<?php

namespace Modules\Core\Services;

use Illuminate\Http\Request;
use Modules\Core\Models\ItemLookup;

class ItemLookupSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function paginated(ItemLookupDefinition $definition, Request $request): array
    {
        $search = $request->input('q', $request->input('term'));
        $table = $definition->table;

        $query = $definition->modelClass::query()
            ->active()
            ->forCompany($this->companyContext->requireCompanyId($request))
            ->select([
                "{$table}.doc_num",
                "{$table}.name",
                "{$table}.doc_number",
            ])
            ->orderBy("{$table}.name")
            ->orderBy("{$table}.doc_number");

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    "{$table}.doc_num",
                    "{$table}.name",
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (ItemLookup $record): array => $this->item($record));
    }

    /**
     * @return array{id: string, text: string}
     */
    public function item(ItemLookup $record): array
    {
        return [
            'id' => (string) $record->doc_num,
            'text' => $this->label($record),
        ];
    }

    public function label(?ItemLookup $record): ?string
    {
        if (! $record) {
            return null;
        }

        return trim(implode(' / ', array_filter([
            $record->doc_num,
            $record->name,
        ])));
    }
}
