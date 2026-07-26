<?php

namespace Modules\Auth\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Auth\DataTables\ScreenDataVisibilityRulesDataTable;
use Modules\Auth\Http\Requests\BulkDeleteScreenDataVisibilityRulesRequest;
use Modules\Auth\Http\Requests\ScreenDataVisibilityRuleRequest;
use Modules\Auth\Models\ScreenDataVisibilityRule;
use Modules\Auth\Services\ScreenDataVisibilityRuleService;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ScreenDataVisibilityRegistry;
use Modules\Core\Services\SettingService;

class ScreenDataVisibilityRuleController extends Controller
{
    public function __construct(
        private readonly ScreenDataVisibilityRuleService $rules,
        private readonly ScreenDataVisibilityRegistry $registry,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(): View
    {
        return view('modules.auth.screen-data-visibility-rules.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.screen-data-visibility-rules.index'),
        ]);
    }

    public function data(Request $request, ScreenDataVisibilityRulesDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        $selectedUser = $this->selectedUser($request->string('user')->trim()->toString());

        return $this->form('create', selectedUser: $selectedUser);
    }

    public function show(Request $request, ScreenDataVisibilityRule $screenDataVisibilityRule): View
    {
        abort_if($screenDataVisibilityRule->trashed() && ! $request->user()?->can('screen_data_visibility_rules.view_trashed'), 404);

        $this->log($request, 'view', $screenDataVisibilityRule);

        return $this->form('view', $screenDataVisibilityRule);
    }

    public function edit(ScreenDataVisibilityRule $screenDataVisibilityRule): View
    {
        return $this->form('edit', $screenDataVisibilityRule);
    }

    public function clone(ScreenDataVisibilityRule $screenDataVisibilityRule): View
    {
        $token = (string) Str::uuid();
        session()->put($this->cloneSessionKey($token), $screenDataVisibilityRule->doc_num);

        return $this->form('clone', $screenDataVisibilityRule, $token);
    }

    public function store(ScreenDataVisibilityRuleRequest $request): JsonResponse
    {
        $cloneSource = $this->cloneSource($request);
        $record = $this->rules->create($request->validated());
        $this->log($request, $cloneSource ? 'clone' : 'create', $record, $cloneSource ? ['source_doc_num' => $cloneSource->doc_num] : []);

        return response()->json([
            'success' => true,
            'message' => __($cloneSource ? 'screen_data_visibility_rules.messages.cloned' : 'screen_data_visibility_rules.messages.created'),
            ...$this->saveResponse($request, $record, true),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function update(ScreenDataVisibilityRuleRequest $request, ScreenDataVisibilityRule $screenDataVisibilityRule): JsonResponse
    {
        $oldActive = (bool) $screenDataVisibilityRule->is_active;
        $result = $this->rules->update($screenDataVisibilityRule, $request->validated());
        if (! $result['changed']) {
            return response()->json(['success' => false, 'type' => 'no_changes', 'message' => __('common.messages.no_changes')]);
        }

        $record = $result['record'];
        $this->log($request, 'update', $record, ['changed_fields' => array_keys($result['changes'])]);
        if ($oldActive !== (bool) $record->is_active) {
            $this->log($request, $record->is_active ? 'activate' : 'deactivate', $record);
        }

        return response()->json([
            'success' => true,
            'message' => __('screen_data_visibility_rules.messages.updated'),
            ...$this->saveResponse($request, $record, false),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function destroy(Request $request, ScreenDataVisibilityRule $screenDataVisibilityRule): JsonResponse
    {
        $this->rules->delete($screenDataVisibilityRule);
        $this->log($request, 'delete', $screenDataVisibilityRule);

        return response()->json(['success' => true, 'message' => __('screen_data_visibility_rules.messages.deleted')]);
    }

    public function bulkDelete(BulkDeleteScreenDataVisibilityRulesRequest $request): JsonResponse
    {
        $records = $this->rules->bulkDelete($request->validated('doc_nums'));
        $records->each(fn (ScreenDataVisibilityRule $record) => $this->log($request, 'delete', $record, ['bulk' => true]));
        $count = $records->count();

        return response()->json(['success' => true, 'message' => __('screen_data_visibility_rules.messages.bulk_deleted', compact('count'))]);
    }

    public function restore(Request $request, string $screenDataVisibilityRule): JsonResponse
    {
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
        $record = ScreenDataVisibilityRule::withTrashed()->forCompany($companyId)->where('doc_num', $screenDataVisibilityRule)->firstOrFail();

        try {
            $record = $this->rules->restore($record);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->log($request, 'restore', $record);

        return response()->json(['success' => true, 'message' => __('screen_data_visibility_rules.messages.restored')]);
    }

    private function form(string $mode, ?ScreenDataVisibilityRule $record = null, ?string $cloneSourceToken = null, ?User $selectedUser = null): View
    {
        $record?->loadMissing(['user', 'company']);
        $selectedUser ??= $record?->user;

        return view('modules.auth.screen-data-visibility-rules.form', [
            'mode' => $mode,
            'record' => $record,
            'selectedUser' => $selectedUser,
            'screenOptions' => $this->registry->selectorOptions(),
            'action' => in_array($mode, ['create', 'clone'], true)
                ? route('admin.screen-data-visibility-rules.store')
                : route('admin.screen-data-visibility-rules.update', $record?->doc_num),
            'method' => in_array($mode, ['create', 'clone'], true) ? 'POST' : 'PUT',
            'cloneSourceToken' => $cloneSourceToken,
            'metadata' => $this->metadata($record),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
        ]);
    }

    private function cloneSource(ScreenDataVisibilityRuleRequest $request): ?ScreenDataVisibilityRule
    {
        $token = $request->string('clone_source_token')->trim()->toString();
        if ($token === '') {
            return null;
        }

        $docNum = (string) $request->session()->pull($this->cloneSessionKey($token), '');
        $companyId = app(OperatingCompanyContextService::class)->requireCompanyId();
        $source = ScreenDataVisibilityRule::query()->forCompany($companyId)->where('doc_num', $docNum)->first();
        if (! $source) {
            throw ValidationException::withMessages(['screen_key' => __('screen_data_visibility_rules.validation.clone_not_allowed')]);
        }

        return $source;
    }

    private function cloneSessionKey(string $token): string
    {
        return 'screen_data_visibility_rules.clone_sources.'.$token;
    }

    private function selectedUser(string $docNum): ?User
    {
        return $docNum === '' ? null : User::query()->where('doc_num', $docNum)->first();
    }

    /** @return array<string, string> */
    private function urls(ScreenDataVisibilityRule $record): array
    {
        return [
            'show' => route('admin.screen-data-visibility-rules.show', $record->doc_num),
            'edit' => route('admin.screen-data-visibility-rules.edit', $record->doc_num),
            'clone' => route('admin.screen-data-visibility-rules.clone', $record->doc_num),
            'update' => route('admin.screen-data-visibility-rules.update', $record->doc_num),
            'destroy' => route('admin.screen-data-visibility-rules.destroy', $record->doc_num),
        ];
    }

    /** @return array<string, mixed> */
    private function saveResponse(Request $request, ScreenDataVisibilityRule $record, bool $creating): array
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';
        if ($creating && $action === 'save') {
            $action = 'save_new';
        }

        $redirect = match ($action) {
            'save_view' => route('admin.screen-data-visibility-rules.show', $record->doc_num),
            'save_edit' => route('admin.screen-data-visibility-rules.edit', $record->doc_num),
            'save_back' => route('admin.screen-data-visibility-rules.index'),
            'save_clone' => route('admin.screen-data-visibility-rules.clone', $record->doc_num),
            default => null,
        };

        return array_filter([
            'submit_action' => $action,
            'redirect' => $redirect,
            'reset_form' => $action === 'save_new' ? true : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<int, array{label: string, url?: string|null, active?: bool}> */
    private function breadcrumbs(string $mode, ?ScreenDataVisibilityRule $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'clone' => [['label' => (string) $record?->doc_num, 'url' => route('admin.screen-data-visibility-rules.show', $record?->doc_num)], ['label' => __('common.actions.clone')]],
            'edit' => [['label' => (string) $record?->doc_num, 'url' => route('admin.screen-data-visibility-rules.show', $record?->doc_num)], ['label' => __('breadcrumb.edit')]],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.screen-data-visibility-rules.index', $extra);
    }

    /** @return array<string, string|null> */
    private function metadata(?ScreenDataVisibilityRule $record): array
    {
        if (! $record) {
            return array_fill_keys(['created_by', 'created_at', 'updated_by', 'updated_at', 'deleted_by', 'deleted_at', 'restored_by', 'restored_at'], null);
        }

        $users = User::withTrashed()
            ->whereIn('id', array_filter([$record->created_by, $record->updated_by, $record->deleted_by, $record->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $format = app(SettingService::class)->dateTimeFormat();

        return [
            'created_by' => $users->get($record->created_by)?->name,
            'created_at' => $record->created_at?->format($format),
            'updated_by' => $users->get($record->updated_by)?->name,
            'updated_at' => $record->updated_at?->format($format),
            'deleted_by' => $users->get($record->deleted_by)?->name,
            'deleted_at' => $record->deleted_at?->format($format),
            'restored_by' => $users->get($record->restored_by)?->name,
            'restored_at' => $record->restored_at?->format($format),
        ];
    }

    /** @param array<string, mixed> $extra */
    private function log(Request $request, string $action, ScreenDataVisibilityRule $record, array $extra = []): void
    {
        $definition = $this->registry->definition($record->screen_key) ?? [];
        $user = $record->relationLoaded('user') ? $record->user : User::withTrashed()->find($record->user_id);

        $this->activityLogger->log($request, 'screen_data_visibility_rules', $action, 'success', [
            'subject' => $record,
            'company_id' => $record->company_id,
            'properties_only' => true,
            'properties' => [
                'rule_doc_num' => $record->doc_num,
                'user_doc_num' => $user?->doc_num,
                'user_name' => $user?->name,
                'screen_key' => $record->screen_key,
                'screen_title' => $definition[app()->getLocale() === 'ar' ? 'title_ar' : 'title_en'] ?? $record->screen_key,
                'record_scope' => $record->record_scope?->value,
                'max_visible_records' => $record->max_visible_records,
                'duration_value' => $record->duration_value,
                'duration_unit' => $record->duration_unit?->value,
                'is_active' => $record->is_active,
                ...$extra,
            ],
        ]);
    }
}
