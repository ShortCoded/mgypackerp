<?php

namespace Modules\HR\Http\Controllers;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Services\BranchService;
use Modules\Core\Services\CompanyService;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;
use Modules\HR\Services\HrFoundationService;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;
use Modules\HR\Services\HrLookupService;
use Modules\HR\Services\HrSelect2InlineSupport;
use Modules\HR\Services\HrSelect2Service;

class HrSelect2InlineController extends Controller
{
    public function __construct(
        private readonly HrLookupRegistry $lookupRegistry,
        private readonly HrFoundationRegistry $foundationRegistry,
        private readonly HrLookupService $lookupService,
        private readonly HrFoundationService $foundationService,
        private readonly HrSelect2Service $select2,
        private readonly CompanyService $companies,
        private readonly BranchService $branches,
    ) {}

    public function storeLookup(Request $request, string $resource): JsonResponse
    {
        $definition = $this->lookupDefinition($resource);
        abort_unless((bool) $request->user()?->can($definition->permission('create')), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique($definition->table, 'name')->withoutTrashed()],
            'notes' => ['nullable', 'string'],
            'target_select' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => __('hr.validation.name_unique'),
        ], [
            'name' => __('common.fields.name'),
            'notes' => __('common.fields.notes'),
        ]);

        try {
            $record = $this->lookupService->create($definition, [
                'name' => trim((string) $data['name']),
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => __('hr.inline_lookup.created'),
            'data' => [
                'option' => $this->select2->asSelect2Option($record),
            ],
        ]);
    }

    public function storeCompany(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('companies.create'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('companies', 'name')->withoutTrashed()],
            'notes' => ['nullable', 'string'],
            'target_select' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => __('companies.validation.name_unique'),
        ], [
            'name' => __('common.fields.name'),
            'notes' => __('common.fields.notes'),
        ]);

        try {
            $result = $this->companies->create([
                'name' => trim((string) $data['name']),
                'status' => 'active',
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'name' => $exception->getMessage(),
            ]);
        }

        $company = $result['company'];

        return response()->json([
            'success' => true,
            'message' => __('hr.inline_lookup.created'),
            'data' => [
                'option' => $this->select2->asSelect2Option($company),
            ],
        ]);
    }

    public function storeBranch(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('branches.create'), 403);

        $data = $request->validate([
            'company_doc_num' => [
                'required',
                'string',
                Rule::exists('companies', 'doc_num')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('branches', 'name')->where(function ($query) use ($request): void {
                    $companyId = (int) Company::query()
                        ->active()
                        ->where('doc_num', trim((string) $request->input('company_doc_num')))
                        ->value('id');

                    $query->where('company_id', $companyId);
                })->withoutTrashed(),
            ],
            'notes' => ['nullable', 'string'],
            'target_select' => ['nullable', 'string', 'max:255'],
        ], [], [
            'name' => __('common.fields.name'),
            'notes' => __('common.fields.notes'),
            'company_doc_num' => __('hr.employees.attributes.company_doc_num'),
        ]);

        try {
            $result = $this->branches->create([
                'name' => trim((string) $data['name']),
                'company_doc_num' => trim((string) $data['company_doc_num']),
                'type' => Branch::TypeAdministrative,
                'status' => 'active',
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
            ]);
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        $branch = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('hr.inline_lookup.created'),
            'data' => [
                'option' => $this->select2->asSelect2Option($branch),
            ],
        ]);
    }

    public function storeFoundation(Request $request, string $resource): JsonResponse
    {
        $definition = $this->foundationDefinition($resource);
        abort_unless(HrSelect2InlineSupport::foundationSupportsQuickCreate($definition), 404);
        abort_unless((bool) $request->user()?->can($definition->permission('create')), 403);

        $rules = [
            'name' => ['required', 'string', 'max:255', Rule::unique($definition->table, 'name')->withoutTrashed()],
            'notes' => ['nullable', 'string'],
            'target_select' => ['nullable', 'string', 'max:255'],
        ];

        $data = $request->validate($rules, [
            'name.unique' => __('hr.validation.name_unique'),
        ], [
            'name' => __('common.fields.name'),
            'notes' => __('common.fields.notes'),
        ]);

        $payload = $this->foundationQuickCreatePayload($definition, $data);

        try {
            $record = $this->foundationService->create($definition, $payload);
        } catch (QueryException $exception) {
            $this->throwValidationExceptionIfUniqueConflict($exception);

            throw $exception;
        }

        return response()->json([
            'success' => true,
            'message' => __('hr.inline_lookup.created'),
            'data' => [
                'option' => $this->select2->asSelect2Option($record),
            ],
        ]);
    }

    private function lookupDefinition(string $resource): HrLookupDefinition
    {
        return $this->lookupRegistry->get($resource);
    }

    private function foundationDefinition(string $resource): HrFoundationDefinition
    {
        return $this->foundationRegistry->get($resource);
    }

    /**
     * @param  array{name: string, notes?: string|null}  $data
     * @return array<string, mixed>
     */
    private function foundationQuickCreatePayload(HrFoundationDefinition $definition, array $data): array
    {
        $payload = [
            'name' => trim((string) $data['name']),
            'status' => 'active',
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];

        foreach ($definition->fields as $field) {
            if (! array_key_exists('default', $field)) {
                continue;
            }

            $name = (string) $field['name'];
            $default = $field['default'];
            $type = (string) ($field['type'] ?? 'text');

            $payload[$name] = match ($type) {
                'checkbox' => (bool) $default,
                'weekdays' => is_array($default) ? $default : [],
                default => $default,
            };
        }

        foreach ($definition->fields as $field) {
            $name = (string) $field['name'];

            if (array_key_exists($name, $payload)) {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');
            $rules = $field['rules'] ?? [];

            if ($type === 'text' && in_array('nullable', $rules, true)) {
                $payload[$name] = '';
            }
        }

        if (array_key_exists('code', $payload) && trim((string) $payload['code']) === '') {
            $payload['code'] = 'AUTO-'.strtoupper(bin2hex(random_bytes(5)));
        }

        return $payload;
    }

    private function throwValidationExceptionIfUniqueConflict(QueryException $exception): void
    {
        $message = $exception->getMessage();

        if (str_contains($message, '_doc_number_unique_active') || str_contains($message, '_doc_num_unique_active')) {
            throw ValidationException::withMessages([
                'doc_number' => __('hr.validation.doc_number_unique'),
            ]);
        }

        if (str_contains($message, '_name_unique_active')) {
            throw ValidationException::withMessages([
                'name' => __('hr.validation.name_unique'),
            ]);
        }
    }
}
