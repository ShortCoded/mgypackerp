<?php

namespace Modules\Auth\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Models\ScreenDataVisibilityRule;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ScreenDataVisibilityRegistry;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class ScreenDataVisibilityRulesDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
        private readonly ScreenDataVisibilityRegistry $registry,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $canView = (bool) $request->user()?->can('screen_data_visibility_rules.view');
        $query = $this->baseQuery($this->trashFilter($request));
        $query = $this->companies->applyCompanyScope($query, 'screen_data_visibility_rules', $request);
        $userDocNum = $request->string('user')->trim()->toString();

        if ($userDocNum !== '') {
            $query->whereHas('user', fn (Builder $userQuery): Builder => $userQuery->where('doc_num', $userDocNum));
        }

        $query->leftJoin('users as rule_users', 'rule_users.id', '=', 'screen_data_visibility_rules.user_id')
            ->leftJoin('companies', 'companies.id', '=', 'screen_data_visibility_rules.company_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'screen_data_visibility_rules.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'screen_data_visibility_rules.updated_by')
            ->select([
                'screen_data_visibility_rules.*',
                'rule_users.name as user_name',
                'rule_users.doc_num as user_doc_num',
                'companies.name as company_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ]);

        return DataTables::eloquent($query)
            ->filter(function (Builder $query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'screen_data_visibility_rules.doc_num',
                            'rule_users.name',
                            'rule_users.doc_num',
                            'companies.name',
                            'screen_data_visibility_rules.screen_key',
                            'screen_data_visibility_rules.record_scope',
                            'screen_data_visibility_rules.notes',
                        ],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (ScreenDataVisibilityRule $record): string => view('modules.auth.screen-data-visibility-rules.partials.checkbox', compact('record'))->render())
            ->editColumn('doc_num', fn (ScreenDataVisibilityRule $record): string => $this->docNumColumn($record, $canView))
            ->addColumn('user', fn (ScreenDataVisibilityRule $record): string => $this->ellipsisText(trim($record->user_name.' / '.$record->user_doc_num)))
            ->addColumn('company', fn (ScreenDataVisibilityRule $record): string => $this->ellipsisText($record->company_name))
            ->addColumn('module', fn (ScreenDataVisibilityRule $record): string => $this->ellipsisText($this->definitionLabel($record->screen_key, 'module')))
            ->editColumn('screen_key', fn (ScreenDataVisibilityRule $record): string => $this->ellipsisText($this->definitionLabel($record->screen_key, 'title')))
            ->editColumn('record_scope', fn (ScreenDataVisibilityRule $record): string => $this->plainText(__('screen_data_visibility_rules.scopes.'.$record->record_scope->value)))
            ->editColumn('max_visible_records', fn (ScreenDataVisibilityRule $record): string => $this->plainText($record->max_visible_records ?? __('screen_data_visibility_rules.unlimited')))
            ->addColumn('duration', fn (ScreenDataVisibilityRule $record): string => $this->plainText($record->duration_value ? $record->duration_value.' '.__('screen_data_visibility_rules.duration_units.'.$record->duration_unit?->value) : __('screen_data_visibility_rules.unlimited')))
            ->editColumn('is_active', fn (ScreenDataVisibilityRule $record): string => view('modules.auth.screen-data-visibility-rules.partials.status', compact('record'))->render())
            ->addColumn('created_by', fn (ScreenDataVisibilityRule $record): string => $this->ellipsisText($record->created_by_name))
            ->editColumn('created_at', fn (ScreenDataVisibilityRule $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (ScreenDataVisibilityRule $record): string => $this->ellipsisText($record->updated_by_name))
            ->editColumn('updated_at', fn (ScreenDataVisibilityRule $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (ScreenDataVisibilityRule $record): string => view('modules.auth.screen-data-visibility-rules.partials.actions', compact('record'))->render())
            ->orderColumn('doc_num', 'screen_data_visibility_rules.doc_number $1')
            ->orderColumn('user', 'rule_users.name $1')
            ->orderColumn('company', 'companies.name $1')
            ->orderColumn('module', 'screen_data_visibility_rules.screen_key $1')
            ->orderColumn('screen_key', 'screen_data_visibility_rules.screen_key $1')
            ->orderColumn('record_scope', 'screen_data_visibility_rules.record_scope $1')
            ->orderColumn('max_visible_records', 'screen_data_visibility_rules.max_visible_records $1')
            ->orderColumn('duration', 'screen_data_visibility_rules.duration_value $1')
            ->orderColumn('is_active', 'screen_data_visibility_rules.is_active $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'screen_data_visibility_rules.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'screen_data_visibility_rules.updated_at $1')
            ->removeColumn(
                'id',
                'doc_number',
                'user_id',
                'company_id',
                'deleted_by',
                'restored_by',
            )
            ->rawColumns(['checkbox', 'doc_num', 'user', 'company', 'module', 'screen_key', 'record_scope', 'max_visible_records', 'duration', 'is_active', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    /** @return Builder<ScreenDataVisibilityRule> */
    private function baseQuery(string $trashFilter): Builder
    {
        $query = ScreenDataVisibilityRule::query();

        return match ($trashFilter) {
            'trashed' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => $query,
        };
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('screen_data_visibility_rules.view_trashed')) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->trim()->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(ScreenDataVisibilityRule $record, bool $canView): string
    {
        $label = e((string) $record->doc_num);

        return $canView
            ? '<a class="fw-semibold dt-code-value" href="'.e(route('admin.screen-data-visibility-rules.show', $record->doc_num)).'">'.$label.'</a>'
            : '<span class="fw-semibold dt-code-value">'.$label.'</span>';
    }

    private function definitionLabel(string $screenKey, string $field): string
    {
        $definition = $this->registry->definition($screenKey);
        if (! is_array($definition)) {
            return $screenKey;
        }

        return (string) ($definition[$field.'_'.(app()->getLocale() === 'ar' ? 'ar' : 'en')] ?? $screenKey);
    }
}
