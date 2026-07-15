<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Core\Models\Company;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrGovernorate;
use Modules\HR\Models\HrLookupModel;

class CompanyLocationSelect2Service
{
    /**
     * @var array<string, class-string<HrLookupModel>>
     */
    private array $models = [
        'countries' => HrCountry::class,
        'governorates' => HrGovernorate::class,
        'cities' => HrCity::class,
        'areas' => HrArea::class,
    ];

    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
    ) {}

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function paginated(string $type, Request $request): array
    {
        $model = $this->modelFor($type);
        $search = $request->input('q', $request->input('term'));
        $table = (new $model)->getTable();
        $columns = ["{$table}.doc_num", "{$table}.name", "{$table}.notes", "{$table}.doc_number", ...$this->parentColumns($type, $table)];

        /** @var Builder<HrLookupModel> $query */
        $query = $model::query()
            ->select($columns)
            ->orderBy('name')
            ->orderBy('doc_number');

        $this->applyParentFilter($query, $type, $request);

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    $query->getModel()->getTable().'.doc_num',
                    $query->getModel()->getTable().'.name',
                    $query->getModel()->getTable().'.notes',
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (HrLookupModel $item): array => $this->item($item));
    }

    /**
     * @return array{country: array{id: string, text: string}|null, governorate: array{id: string, text: string}|null, city: array{id: string, text: string}|null, area: array{id: string, text: string}|null}
     */
    public function selectedForCompany(Company $company): array
    {
        return [
            'country' => $this->selectedRelation($company, 'country'),
            'governorate' => $this->selectedRelation($company, 'governorate'),
            'city' => $this->selectedRelation($company, 'city'),
            'area' => $this->selectedRelation($company, 'area'),
        ];
    }

    /**
     * @return class-string<HrLookupModel>
     */
    private function modelFor(string $type): string
    {
        return $this->models[$type] ?? throw new InvalidArgumentException("Unknown company location type [{$type}].");
    }

    /**
     * @return array{id: string, text: string}
     */
    private function item(HrLookupModel $item): array
    {
        $payload = [
            'id' => (string) $item->doc_num,
            'text' => (string) $item->name,
        ];

        if ($item instanceof HrGovernorate && $item->country instanceof HrCountry) {
            $payload['country_doc_num'] = (string) $item->country->doc_num;
        }

        if ($item instanceof HrCity && $item->governorate instanceof HrGovernorate) {
            $payload['governorate_doc_num'] = (string) $item->governorate->doc_num;
        }

        if ($item instanceof HrArea && $item->city instanceof HrCity) {
            $payload['city_doc_num'] = (string) $item->city->doc_num;
        }

        return $payload;
    }

    /**
     * @return array{id: string, text: string}|null
     */
    private function selectedRelation(Company $company, string $relation): ?array
    {
        $item = $company->{$relation}()
            ->select(['doc_num', 'name'])
            ->first();

        return $item instanceof HrLookupModel ? $this->item($item) : null;
    }

    /**
     * @param  Builder<HrLookupModel>  $query
     */
    private function applyParentFilter(Builder $query, string $type, Request $request): void
    {
        match ($type) {
            'governorates' => $this->filterByParentDocNum($query, 'country_id', HrCountry::class, $request->string('country_doc_num')->trim()->toString()),
            'cities' => $this->filterByParentDocNum($query, 'governorate_id', HrGovernorate::class, $request->string('governorate_doc_num')->trim()->toString()),
            'areas' => $this->filterByParentDocNum($query, 'city_id', HrCity::class, $request->string('city_doc_num')->trim()->toString()),
            default => null,
        };

        if ($type === 'governorates') {
            $query->with('country:id,doc_num');
        }

        if ($type === 'cities') {
            $query->with('governorate:id,doc_num');
        }

        if ($type === 'areas') {
            $query->with('city:id,doc_num');
        }
    }

    /**
     * @return list<string>
     */
    private function parentColumns(string $type, string $table): array
    {
        return match ($type) {
            'governorates' => ["{$table}.country_id"],
            'cities' => ["{$table}.governorate_id"],
            'areas' => ["{$table}.city_id"],
            default => [],
        };
    }

    /**
     * @param  Builder<HrLookupModel>  $query
     * @param  class-string<HrLookupModel>  $parentModel
     */
    private function filterByParentDocNum(Builder $query, string $foreignKey, string $parentModel, string $parentDocNum): void
    {
        if ($parentDocNum === '') {
            return;
        }

        $parentId = $parentModel::query()
            ->where('doc_num', $parentDocNum)
            ->whereNull('deleted_at')
            ->value('id');

        $parentId ? $query->where($foreignKey, $parentId) : $query->whereRaw('1 = 0');
    }
}
