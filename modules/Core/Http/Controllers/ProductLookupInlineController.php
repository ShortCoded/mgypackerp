<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Core\Services\ItemLookupRegistry;
use Modules\Core\Services\ItemLookupSelect2Service;
use Modules\Core\Services\ItemLookupService;
use Modules\Core\Services\OperatingCompanyContextService;

class ProductLookupInlineController extends Controller
{
    public function __construct(
        private readonly ItemLookupRegistry $registry,
        private readonly ItemLookupService $lookups,
        private readonly ItemLookupSelect2Service $select2,
        private readonly OperatingCompanyContextService $companyContext,
    ) {}

    public function store(Request $request, string $lookup): JsonResponse
    {
        $definition = $this->registry->get(str_replace('-', '_', $lookup));

        abort_unless((bool) $request->user()?->can($definition->permission('create')), 403);

        $companyId = $this->companyContext->requireCompanyId($request);
        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique($definition->table, 'name')
                    ->where('company_id', $companyId)
                    ->withoutTrashed(),
            ],
            'notes' => ['nullable', 'string'],
        ], [
            'name.unique' => __('item_lookups.validation.name_unique'),
        ], [
            'name' => __('item_lookups.fields.name'),
            'notes' => __('item_lookups.fields.notes'),
        ]);

        $record = $this->lookups->create($definition, [
            'name' => trim((string) $data['name']),
            'status' => 'active',
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('products.inline_lookup.created'),
            'data' => [
                'lookup' => $definition->key,
                'option' => $this->select2->item($record),
            ],
        ]);
    }
}
