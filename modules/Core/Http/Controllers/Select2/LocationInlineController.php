<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrGovernorate;
use Modules\HR\Models\HrLookupModel;
use Modules\HR\Services\HrLookupRegistry;
use Modules\HR\Services\HrLookupService;

class LocationInlineController extends Controller
{
    public function __construct(
        private readonly HrLookupRegistry $registry,
        private readonly HrLookupService $lookups,
    ) {}

    public function __invoke(Request $request, string $type): JsonResponse
    {
        $definition = $this->registry->get($type);
        abort_unless($this->canCreateLocation($request, $type), 403);

        $data = $request->validate($this->rules($type, $request), [
            'name.unique' => __('hr.validation.name_unique'),
        ], [
            'name' => __('common.fields.name'),
            'notes' => __('common.fields.notes'),
            'country_doc_num' => __('customers.attributes.country'),
            'governorate_doc_num' => __('customers.attributes.governorate'),
            'city_doc_num' => __('customers.attributes.city'),
        ]);

        try {
            $record = $this->lookups->create($definition, [
                'name' => trim((string) $data['name']),
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                ...$this->parentPayload($type, $data),
            ]);
        } catch (QueryException $exception) {
            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => __('business_partners.messages.location_created'),
            'data' => [
                'option' => [
                    'id' => (string) $record->doc_num,
                    'text' => (string) $record->name,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $type, Request $request): array
    {
        $definition = $this->registry->get($type);
        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique($definition->table, 'name')->withoutTrashed()],
            'notes' => ['nullable', 'string'],
            'target_select' => ['nullable', 'string', 'max:255'],
        ];

        if ($type === 'governorates') {
            $rules['country_doc_num'] = ['nullable', 'string', Rule::exists('hr_countries', 'doc_num')->whereNull('deleted_at')];
        }

        if ($type === 'cities') {
            $rules['governorate_doc_num'] = ['nullable', 'string', Rule::exists('hr_governorates', 'doc_num')->whereNull('deleted_at')];
        }

        if ($type === 'areas') {
            $rules['city_doc_num'] = ['nullable', 'string', Rule::exists('hr_cities', 'doc_num')->whereNull('deleted_at')];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     *
     * @throws ValidationException
     */
    private function parentPayload(string $type, array $data): array
    {
        if ($type === 'governorates') {
            return $this->optionalParentPayload('country_id', HrCountry::class, (string) ($data['country_doc_num'] ?? ''), 'country_doc_num');
        }

        if ($type === 'cities') {
            return $this->optionalParentPayload('governorate_id', HrGovernorate::class, (string) ($data['governorate_doc_num'] ?? ''), 'governorate_doc_num');
        }

        if ($type === 'areas') {
            return $this->optionalParentPayload('city_id', HrCity::class, (string) ($data['city_doc_num'] ?? ''), 'city_doc_num');
        }

        return [];
    }

    /**
     * @param  class-string<HrLookupModel>  $model
     * @return array<string, int>
     */
    private function optionalParentPayload(string $key, string $model, string $docNum, string $field): array
    {
        if (trim($docNum) === '') {
            return [];
        }

        return [$key => $this->parentId($model, $docNum, $field)];
    }

    /**
     * @param  class-string<HrLookupModel>  $model
     */
    private function parentId(string $model, string $docNum, string $field): int
    {
        $id = $model::query()
            ->where('doc_num', trim($docNum))
            ->whereNull('deleted_at')
            ->value('id');

        if (! $id) {
            throw ValidationException::withMessages([
                $field => __('validation.exists', ['attribute' => $field]),
            ]);
        }

        return (int) $id;
    }

    private function canCreateLocation(Request $request, string $type): bool
    {
        $user = $request->user();
        $definition = $this->registry->get($type);

        return (bool) $user?->can($definition->permission('create'))
            || (bool) $user?->can('customers.create')
            || (bool) $user?->can('customers.edit')
            || (bool) $user?->can('suppliers.create')
            || (bool) $user?->can('suppliers.edit');
    }
}
