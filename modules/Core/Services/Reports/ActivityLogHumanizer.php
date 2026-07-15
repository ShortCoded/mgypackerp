<?php

namespace Modules\Core\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Core\Services\DateFormatService;
use Spatie\Activitylog\Models\Activity;

class ActivityLogHumanizer
{
    private const ExportJsonLimit = 10000;

    public function __construct(
        private readonly DateFormatService $dates,
        private readonly ReportSanitizer $sanitizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function row(Activity $activity): array
    {
        $properties = $this->properties($activity);
        $activityKey = $this->activityKey($activity);
        $record = $this->recordLabel($activity, $properties);
        $changes = $this->changes($activity, $properties, $record);
        $technicalDetails = $this->originalPropertiesJson($properties, false, self::ExportJsonLimit);

        return [
            'date_time' => $this->dates->formatDateTime($activity->created_at),
            'user' => $this->actorLabel($activity),
            'area' => $this->areaLabel($activity),
            'activity' => $this->activityLabelFor($activity, $properties),
            'result' => $this->statusLabel($activity->status),
            'result_class' => $this->statusClass($activity->status),
            'record' => $record,
            'company' => $this->companyLabel($activity),
            'ip_address' => $this->displayText($activity->ip_address),
            'summary' => $this->summary($activity, $properties, $record),
            'changes' => $this->changesText($changes, $changes === [] ? $technicalDetails : null),
            'has_technical_fallback' => $changes === [] && $technicalDetails !== null,
            'has_technical_details' => $technicalDetails !== null,
            'technical_details' => $technicalDetails ?? '',
        ];
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            __('activity_logs.fields.date_time'),
            __('activity_logs.fields.user'),
            __('activity_logs.fields.area'),
            __('activity_logs.fields.activity'),
            __('activity_logs.fields.result'),
            __('activity_logs.fields.record'),
            __('activity_logs.fields.company'),
            __('activity_logs.fields.ip_address'),
            __('activity_logs.fields.summary'),
            __('activity_logs.fields.changes'),
        ];
    }

    /**
     * @return list<string>
     */
    public function exportMap(Activity $activity, bool $forPdf = false): array
    {
        $row = $this->row($activity);
        $changes = (bool) ($row['has_technical_fallback'] ?? false)
            ? ''
            : $row['changes'];

        return [
            $row['date_time'],
            $row['user'],
            $row['area'],
            $row['activity'],
            $row['result'],
            $row['record'],
            $row['company'],
            $row['ip_address'],
            $row['summary'],
            $changes,
        ];
    }

    /**
     * @return array{summary: string, sections: list<array{title: string, collapsed?: bool, items: list<array{label: string, value: mixed, type?: string}>}>}
     */
    public function details(Activity $activity): array
    {
        $row = $this->row($activity);
        $properties = $this->properties($activity);
        $changes = $this->changes($activity, $properties, (string) $row['record']);
        $technicalDetails = $this->originalPropertiesJson($properties, true, self::ExportJsonLimit);
        $structuredChangesJson = $this->structuredChangesJson($properties);
        $structuredChanges = $this->structuredChanges($properties);
        $recordContextJson = $this->recordContextJson($activity, $properties);
        $extra = $this->safeExtraProperties($properties);

        $sections = [
            [
                'title' => __('activity_logs.fields.basic_info'),
                'items' => [
                    ['label' => __('activity_logs.fields.date_time'), 'value' => $row['date_time']],
                    ['label' => __('activity_logs.fields.user'), 'value' => $row['user']],
                    ['label' => __('activity_logs.fields.area'), 'value' => $row['area']],
                    ['label' => __('activity_logs.fields.activity'), 'value' => $row['activity']],
                    ['label' => __('activity_logs.fields.result'), 'value' => $row['result']],
                    ['label' => __('activity_logs.fields.summary'), 'value' => $row['summary']],
                ],
            ],
            [
                'title' => __('activity_logs.fields.target'),
                'items' => [
                    ['label' => __('activity_logs.fields.subject_type'), 'value' => $this->recordTypeLabel($activity)],
                    ['label' => __('activity_logs.fields.record'), 'value' => $row['record']],
                    ['label' => __('activity_logs.fields.company'), 'value' => $row['company']],
                ],
            ],
            [
                'title' => __('activity_logs.fields.changes'),
                'items' => $structuredChanges !== []
                    ? $this->structuredChangeItems($structuredChanges)
                    : ($changes === []
                    ? [[
                        'label' => __('activity_logs.fields.changes'),
                        'value' => $technicalDetails !== null
                            ? __('activity_logs.messages.technical_details_available')
                            : '',
                    ]]
                    : collect($changes)->map(fn (string $change): array => ['label' => '', 'value' => $change])->values()->all()),
            ],
            [
                'title' => __('activity_logs.fields.request_device'),
                'items' => [
                    ['label' => __('activity_logs.fields.ip_address'), 'value' => $row['ip_address']],
                    ['label' => __('activity_logs.fields.method'), 'value' => $this->displayText($activity->method)],
                    ['label' => __('activity_logs.fields.system_page'), 'value' => $this->pageLabel($activity->url)],
                    ['label' => __('activity_logs.fields.url'), 'value' => $this->displayText($activity->url)],
                ],
            ],
        ];

        if ($extra !== []) {
            $sections[] = [
                'title' => __('activity_logs.fields.additional_details'),
                'items' => collect($extra)
                    ->map(fn (mixed $value, string $key): array => [
                        'label' => $this->fieldLabel($key),
                        'value' => $this->displayValue($value),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $sections[] = [
            'title' => __('activity_logs.fields.raw_properties'),
            'collapsed' => true,
            'items' => [
                [
                    'label' => __('activity_logs.fields.changes_json'),
                    'value' => $structuredChangesJson ?? __('activity_logs.messages.no_structured_changes_detected'),
                    'type' => $structuredChangesJson !== null ? 'json' : 'text',
                ],
                [
                    'label' => __('activity_logs.fields.record_json'),
                    'value' => $recordContextJson ?? __('activity_logs.messages.no_technical_details'),
                    'type' => $recordContextJson !== null ? 'json' : 'text',
                ],
                [
                    'label' => __('activity_logs.fields.original_properties_json'),
                    'value' => $technicalDetails ?? __('activity_logs.messages.no_technical_details'),
                    'type' => $technicalDetails !== null ? 'json' : 'text',
                ],
                ['label' => __('activity_logs.fields.activity_key'), 'value' => $this->activityKey($activity)],
                ['label' => __('activity_logs.fields.event_key'), 'value' => $this->displayText($activity->event)],
                ['label' => __('activity_logs.fields.description'), 'value' => $this->displayText($activity->description)],
                ['label' => __('activity_logs.fields.system_page'), 'value' => $this->pageLabel($activity->url)],
            ],
        ];

        return [
            'summary' => $row['summary'],
            'sections' => $sections,
        ];
    }

    public function activityLabel(?string $key): string
    {
        $key = trim((string) $key);

        if ($key === '') {
            return __('activity_logs.messages.unknown_activity');
        }

        $translated = $this->arrayTranslation('activity_logs.activities', $key);

        if ($translated !== null) {
            return $translated;
        }

        $crudFallback = $this->crudActivityLabel($key);

        if ($crudFallback !== null) {
            return $crudFallback;
        }

        return Str::of(str_replace(['.', '_'], ' ', $key))->headline()->toString();
    }

    public function areaLabel(Activity|string|null $activityOrKey): string
    {
        if ($activityOrKey instanceof Activity) {
            $key = $this->areaKey($activityOrKey);
        } else {
            $key = trim((string) $activityOrKey);
        }

        if ($key === '') {
            return __('activity_logs.areas.system');
        }

        $translated = $this->arrayTranslation('activity_logs.areas', $key);

        return $translated ?? Str::of(str_replace(['.', '_'], ' ', $key))->headline()->toString();
    }

    public function statusLabel(mixed $status): string
    {
        $status = $this->displayText($status);

        if ($status === '') {
            return '';
        }

        $translated = $this->arrayTranslation('activity_logs.statuses', $status);

        return $translated ?? Str::of(str_replace('_', ' ', $status))->headline()->toString();
    }

    public function statusClass(mixed $status): string
    {
        return match (trim((string) $status)) {
            'success' => 'success',
            'failed', 'error' => 'danger',
            'blocked' => 'warning',
            'warning' => 'warning',
            'info' => 'info',
            default => 'secondary',
        };
    }

    public function activityKey(Activity $activity): string
    {
        return trim((string) ($activity->action ?: $activity->event ?: $activity->description ?: ''));
    }

    public function areaKeyForAction(string $action, ?string $module = null, ?string $logName = null): string
    {
        $action = trim($action);

        if (str_starts_with($action, 'hr.')) {
            $resource = $this->actionSegment($action, 1);

            return $resource !== null ? 'hr_'.$resource : 'hr';
        }

        if (str_starts_with($action, 'archive.') || str_starts_with($action, 'file_manager.')) {
            return 'archive';
        }

        if (str_starts_with($action, 'roles.')) {
            return 'roles';
        }

        if (str_starts_with($action, 'users.')) {
            return 'users';
        }

        if (str_starts_with($action, 'companies.')) {
            return 'companies';
        }

        if (str_starts_with($action, 'language.')) {
            return 'language_settings';
        }

        if (str_starts_with($action, 'auth.sessions.') || str_starts_with($action, 'sessions.') || str_starts_with($action, 'lock_screen.')) {
            return 'sessions';
        }

        if (str_starts_with($action, 'profile.')) {
            return 'profile';
        }

        return trim((string) ($module ?: $logName ?: 'system'));
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(Activity $activity): array
    {
        $properties = $activity->properties;

        if ($properties instanceof Collection) {
            return $properties->all();
        }

        return is_array($properties) ? $properties : [];
    }

    public function actorLabel(Activity $activity): string
    {
        return $this->joinLabel($activity->causer_name ?? null, $activity->causer_doc_num ?? null);
    }

    public function companyLabel(Activity $activity): string
    {
        return $this->joinLabel($activity->company_name ?? null, $activity->company_doc_num ?? null);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function recordLabel(Activity $activity, array $properties): string
    {
        $canonical = $this->canonicalRecordLabel($properties, $activity);

        if ($canonical !== null) {
            return $canonical;
        }

        $name = $this->firstString($properties, [
            'new_role_name',
            'role_name',
            'new_user_name',
            'user_name',
            'new_company_name',
            'source_company_name',
            'company_name',
            'source_role_name',
            'source_user_name',
            'source_name',
            'new_name',
            'old_name',
            'file_name',
            'folder_name',
            'name',
        ]);
        $docNum = $this->firstString($properties, [
            'new_doc_num',
            'doc_num',
            'file_doc_num',
            'folder_doc_num',
            'role_doc_num',
            'user_doc_num',
            'company_doc_num',
            'source_doc_num',
        ]);
        $type = $this->recordTypeLabel($activity);

        if ($name !== null && $docNum !== null) {
            return "{$type} \"{$name}\" ({$docNum})";
        }

        if ($name !== null) {
            return "{$type} \"{$name}\"";
        }

        if ($docNum !== null) {
            return "{$type} ({$docNum})";
        }

        return '';
    }

    public function pageLabel(?string $url): string
    {
        $path = is_string($url) ? (parse_url($url, PHP_URL_PATH) ?: $url) : '';

        if ($path === '') {
            return __('activity_logs.fields.system_page');
        }

        if (str_starts_with($path, '/lang/')) {
            return __('activity_logs.pages.language_switch');
        }

        if (str_contains($path, '/admin/roles')) {
            return str_contains($path, '/edit') ? __('activity_logs.pages.role_edit') : __('activity_logs.pages.roles');
        }

        if (str_contains($path, '/admin/users')) {
            return str_contains($path, '/edit') ? __('activity_logs.pages.user_edit') : __('activity_logs.pages.users');
        }

        if (str_contains($path, '/admin/companies')) {
            return str_contains($path, '/edit') ? __('activity_logs.pages.company_edit') : __('activity_logs.pages.companies');
        }

        if (str_contains($path, '/admin/file-manager')) {
            return __('activity_logs.pages.file_manager');
        }

        if (str_contains($path, '/admin/hr/')) {
            return __('activity_logs.pages.hr_lookup');
        }

        return __('activity_logs.fields.system_page');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function activityLabelFor(Activity $activity, array $properties): string
    {
        $record = $this->canonicalRecord($properties);
        $actionType = $this->canonicalActionType($properties);

        if ($record !== null && $actionType !== null) {
            $labelKey = data_get($properties, 'action.label_key');

            if (is_string($labelKey)) {
                $label = __($labelKey);

                if ($label !== $labelKey) {
                    return $label;
                }
            }

            $resource = (string) ($record['type'] ?? '');
            $resourceLabel = $this->recordTypeLabelForResource($resource, $activity);
            $verbLabel = $this->arrayTranslation('activity_logs.activity_verbs', $actionType);

            if ($verbLabel !== null) {
                return __('activity_logs.summaries.resource_activity', [
                    'resource' => $resourceLabel,
                    'activity' => $verbLabel,
                ]);
            }
        }

        return $this->activityLabel($this->activityKey($activity));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function canonicalSummary(Activity $activity, array $properties, string $record): ?string
    {
        $actionType = $this->canonicalActionType($properties);

        if ($actionType === null) {
            return null;
        }

        if ($actionType === 'bulk_delete') {
            $count = (int) data_get($properties, 'bulk.count', 0);
            $docNums = data_get($properties, 'bulk.doc_nums', []);

            if (is_array($docNums) && $docNums !== []) {
                return __('activity_logs.canonical.bulk_delete', [
                    'count' => $count,
                    'records' => implode(', ', array_map(fn (mixed $docNum): string => $this->displayValue($docNum), $docNums)),
                ]);
            }
        }

        if ($record !== '') {
            $summary = $this->arrayTranslation('activity_logs.canonical', $actionType);

            if ($summary !== null) {
                return __($summary, ['record' => $record]);
            }
        }

        return $this->activityLabelFor($activity, $properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<string>
     */
    private function canonicalChanges(array $properties): array
    {
        $changes = data_get($properties, 'changes');

        if (! is_array($changes) || $changes === []) {
            $actionType = $this->canonicalActionType($properties);

            if ($actionType === 'bulk_delete') {
                $count = (int) data_get($properties, 'bulk.count', 0);
                $docNums = data_get($properties, 'bulk.doc_nums', []);

                $summary = is_array($docNums) && $docNums !== []
                    ? __('activity_logs.canonical.bulk_delete', [
                        'count' => $count,
                        'records' => implode(', ', array_map(fn (mixed $docNum): string => $this->displayValue($docNum), $docNums)),
                    ])
                    : null;

                return $summary !== null ? [$summary] : [];
            }

            return [];
        }

        $resource = data_get($properties, 'record.type');

        return collect($changes)
            ->map(function (mixed $change, string|int $field) use ($resource): ?string {
                if (! is_string($field) || $this->isTechnicalProperty($field)) {
                    return null;
                }

                if (! is_array($change)) {
                    return $this->fieldLabel($field, is_string($resource) ? $resource : null).': '.$this->displayValue($change);
                }

                return __('activity_logs.summaries.field_changed', [
                    'field' => $this->fieldLabel($field, is_string($resource) ? $resource : null),
                    'old' => $this->displayValueForField($field, $change['old'] ?? null),
                    'new' => $this->displayValueForField($field, $change['new'] ?? null),
                ]);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function canonicalRecordLabel(array $properties, Activity $activity): ?string
    {
        $record = $this->canonicalRecord($properties);

        if ($record === null) {
            return null;
        }

        return $this->formatCanonicalRecord($record, $activity);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array{type?: string, label?: mixed, doc_num?: mixed}|null
     */
    private function canonicalRecord(array $properties): ?array
    {
        $record = $properties['record'] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * @param  array{type?: string, label?: mixed, doc_num?: mixed}  $record
     */
    private function formatCanonicalRecord(array $record, Activity $activity): string
    {
        $resource = is_string($record['type'] ?? null) ? trim($record['type']) : '';
        $label = $this->displayValue($record['label'] ?? null);
        $docNum = $this->displayValue($record['doc_num'] ?? null);
        $type = $this->recordTypeLabelForResource($resource, $activity);
        $hasLabel = $label !== '';
        $hasDocNum = $docNum !== '';

        if ($hasLabel && $hasDocNum) {
            return "{$type} \"{$label}\" ({$docNum})";
        }

        if ($hasLabel) {
            return "{$type} \"{$label}\"";
        }

        if ($hasDocNum) {
            return "{$type} ({$docNum})";
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function canonicalActionType(array $properties): ?string
    {
        $type = data_get($properties, 'action.type');

        return is_string($type) && trim($type) !== '' ? trim($type) : null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function summary(Activity $activity, array $properties, string $record): string
    {
        $canonical = $this->canonicalSummary($activity, $properties, $record);

        if ($canonical !== null) {
            return $canonical;
        }

        $key = $this->activityKey($activity);
        $activityLabel = $this->activityLabel($key);

        if ($key === 'language.changed') {
            $oldLocale = $this->localeLabel($properties['old_locale'] ?? null);
            $newLocale = $this->localeLabel($properties['new_locale'] ?? null);

            if ($oldLocale !== null && $newLocale !== null) {
                return __('activity_logs.summaries.language_changed_from_to', ['old' => $oldLocale, 'new' => $newLocale]);
            }

            if ($newLocale !== null) {
                return __('activity_logs.summaries.language_changed_to', ['new' => $newLocale]);
            }
        }

        if (str_contains($key, 'doc_number.changed')) {
            $old = $this->firstString($properties, ['old_doc_num', 'old_doc_number']);
            $new = $this->firstString($properties, ['new_doc_num', 'new_doc_number']);

            if ($old !== null && $new !== null) {
                return __('activity_logs.summaries.document_number_changed', [
                    'old' => $old,
                    'new' => $new,
                ]);
            }
        }

        if (str_contains($key, 'status.changed')) {
            $old = $this->statusValue($properties['old_status'] ?? null);
            $new = $this->statusValue($properties['new_status'] ?? null);

            if ($old !== null && $new !== null) {
                return __('activity_logs.summaries.status_changed', [
                    'old' => $old,
                    'new' => $new,
                ]);
            }
        }

        if ($record !== '') {
            $summaryKey = $this->summaryKeyForActivity($key);

            if ($summaryKey !== null) {
                return __("activity_logs.summaries.{$summaryKey}", [
                    'activity' => $activityLabel,
                    'record' => $record,
                ]);
            }
        }

        if ($record !== '') {
            return __('activity_logs.summaries.activity_for_record', [
                'activity' => $activityLabel,
                'record' => $record,
            ]);
        }

        return $activityLabel;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<string>
     */
    private function changes(Activity $activity, array $properties, string $record): array
    {
        $changes = [];
        $activityKey = $this->activityKey($activity);
        $canonicalChanges = $this->canonicalChanges($properties);

        if ($canonicalChanges !== []) {
            return $canonicalChanges;
        }

        if ($activityKey === 'language.changed') {
            $oldLocale = $this->localeLabel($properties['old_locale'] ?? null);
            $newLocale = $this->localeLabel($properties['new_locale'] ?? null);

            if ($oldLocale !== null && $newLocale !== null) {
                $changes[] = __('activity_logs.summaries.language_changed_from_to', ['old' => $oldLocale, 'new' => $newLocale]);
            } elseif ($newLocale !== null) {
                $changes[] = __('activity_logs.summaries.language_changed_to', ['new' => $newLocale]);
            }
        }

        foreach ($properties as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'old_')) {
                continue;
            }

            $field = Str::after($key, 'old_');
            $newKey = 'new_'.$field;

            if (! array_key_exists($newKey, $properties)) {
                continue;
            }

            $changes[] = __('activity_logs.summaries.field_changed', [
                'field' => $this->fieldLabel($field),
                'old' => $this->displayValueForField($field, $properties[$key]),
                'new' => $this->displayValueForField($field, $properties[$newKey]),
            ]);
        }

        $changes = array_merge($changes, $this->nestedChanges($properties));

        $changedFields = $properties['changed_fields'] ?? null;

        if (is_array($changedFields)) {
            foreach ($changedFields as $field) {
                if (is_string($field) && trim($field) !== '') {
                    $changes[] = __('activity_logs.summaries.field_updated', [
                        'field' => $this->fieldLabel($field),
                    ]);
                }
            }
        }

        foreach (['added_permissions', 'removed_permissions', 'bulk_doc_nums', 'conflict_fields'] as $key) {
            $value = $properties[$key] ?? null;

            if (is_array($value) && $value !== []) {
                $changes[] = $this->fieldLabel($key).': '.implode(', ', array_map(fn (mixed $item): string => $this->displayValue($item), $value));
            }
        }

        if (isset($properties['count']) && is_numeric($properties['count'])) {
            $changes[] = __('activity_logs.summaries.records_count', ['count' => (int) $properties['count']]);
        }

        if (isset($properties['reason'])) {
            $changes[] = $this->fieldLabel('reason').': '.$this->displayValue($properties['reason']);
        }

        if ($changes === []) {
            $summary = $this->fallbackChangeSummary($activity, $properties, $record);

            if ($summary !== null && $summary !== '') {
                $changes[] = $summary;
            }
        }

        return array_values(array_unique($changes));
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function changesText(array $changes, ?string $technicalDetails): string
    {
        if ($changes !== []) {
            return implode(' | ', $changes);
        }

        return $technicalDetails !== null
            ? __('activity_logs.messages.technical_details_available')
            : '';
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function fallbackChangeSummary(Activity $activity, array $properties, string $record): ?string
    {
        if ($this->canonicalActionType($properties) !== null) {
            return $this->summary($activity, $properties, $record);
        }

        $key = $this->activityKey($activity);

        if ($key === 'language.changed' || str_contains($key, 'doc_number.changed') || str_contains($key, 'status.changed')) {
            return $this->summary($activity, $properties, $record);
        }

        if ($record !== '' && $this->summaryKeyForActivity($key) !== null) {
            return $this->summary($activity, $properties, $record);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function structuredChangesJson(array $properties): ?string
    {
        $changes = $this->structuredChanges($properties);

        return $changes === []
            ? null
            : $this->technicalJson(['changes' => $changes], true);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<array{field: string, label: string, old: string, new: string, old_raw: mixed, new_raw: mixed}>
     */
    private function structuredChanges(array $properties): array
    {
        $changes = [];
        $seen = [];
        $resource = data_get($properties, 'record.type');
        $resource = is_string($resource) && trim($resource) !== '' ? trim($resource) : null;

        $canonicalChanges = data_get($properties, 'changes');

        if (is_array($canonicalChanges)) {
            foreach ($canonicalChanges as $field => $change) {
                if (! is_string($field)) {
                    continue;
                }

                if (is_array($change) && (array_key_exists('old', $change) || array_key_exists('new', $change))) {
                    $this->addStructuredChange($changes, $seen, $field, $change['old'] ?? null, $change['new'] ?? null, $resource);

                    continue;
                }

                $this->addStructuredChange($changes, $seen, $field, null, $change, $resource);
            }
        }

        foreach ($properties as $key => $oldValue) {
            if (! is_string($key) || ! str_starts_with($key, 'old_')) {
                continue;
            }

            $field = Str::after($key, 'old_');
            $newKey = 'new_'.$field;

            if (array_key_exists($newKey, $properties)) {
                $this->addStructuredChange($changes, $seen, $field, $oldValue, $properties[$newKey], $resource);
            }
        }

        $changedFields = $properties['changed_fields'] ?? null;

        if (is_array($changedFields)) {
            foreach ($changedFields as $field) {
                if (! is_string($field) || trim($field) === '') {
                    continue;
                }

                $oldKey = 'old_'.$field;
                $newKey = 'new_'.$field;

                if (array_key_exists($oldKey, $properties) && array_key_exists($newKey, $properties)) {
                    $this->addStructuredChange($changes, $seen, $field, $properties[$oldKey], $properties[$newKey], $resource);
                }
            }
        }

        $old = $properties['old'] ?? null;
        $attributes = $properties['attributes'] ?? null;

        if (is_array($old) && is_array($attributes)) {
            foreach ($attributes as $field => $newValue) {
                if (! is_string($field) || ! array_key_exists($field, $old)) {
                    continue;
                }

                $this->addStructuredChange($changes, $seen, $field, $old[$field], $newValue, $resource);
            }
        }

        return $changes;
    }

    /**
     * @param  list<array{field: string, label: string, old: string, new: string, old_raw: mixed, new_raw: mixed}>  $changes
     * @return list<array{label: string, value: string}>
     */
    private function structuredChangeItems(array $changes): array
    {
        return collect($changes)
            ->map(fn (array $change): array => [
                'label' => $change['label'],
                'value' => __('activity_logs.summaries.readable_field_change', [
                    'old' => $change['old'],
                    'new' => $change['new'],
                ]),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{field: string, label: string, old: string, new: string, old_raw: mixed, new_raw: mixed}>  $changes
     * @param  array<string, bool>  $seen
     */
    private function addStructuredChange(array &$changes, array &$seen, string $field, mixed $old, mixed $new, ?string $resource = null): void
    {
        $field = trim($field);

        if ($field === '' || $this->isTechnicalProperty($field) || array_key_exists($field, $seen)) {
            return;
        }

        if ($old === $new) {
            return;
        }

        $seen[$field] = true;
        $changes[] = [
            'field' => $field,
            'label' => $this->fieldLabel($field, $resource),
            'old' => $this->displayValueForField($field, $old),
            'new' => $this->displayValueForField($field, $new),
            'old_raw' => $this->technicalRawValue($old),
            'new_raw' => $this->technicalRawValue($new),
        ];
    }

    private function technicalRawValue(mixed $value): mixed
    {
        $raw = $this->sanitizer->redactOriginalPropertiesForReport(['value' => $value]);

        return $raw['value'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function recordContextJson(Activity $activity, array $properties): ?string
    {
        $context = [];

        foreach (['record', 'related', 'bulk', 'meta'] as $key) {
            $value = $properties[$key] ?? null;

            if (is_array($value) && $value !== []) {
                $context[$key] = $value;
            }
        }

        if (! isset($context['record'])) {
            $record = $this->legacyRecordContext($activity, $properties);

            if ($record !== []) {
                $context['record'] = $record;
            }
        }

        if (! isset($context['related'])) {
            $related = $this->legacyRelatedContext($activity, $properties);

            if ($related !== []) {
                $context['related'] = $related;
            }
        }

        if (! isset($context['bulk']) && isset($properties['bulk_doc_nums']) && is_array($properties['bulk_doc_nums'])) {
            $context['bulk'] = [
                'count' => isset($properties['count']) && is_numeric($properties['count'])
                    ? (int) $properties['count']
                    : count($properties['bulk_doc_nums']),
                'doc_nums' => array_values($properties['bulk_doc_nums']),
            ];
        }

        if (! isset($context['meta']) && isset($properties['submit_action']) && is_string($properties['submit_action'])) {
            $context['meta'] = ['submit_action' => $properties['submit_action']];
        }

        $context = $this->sanitizer->redactOriginalPropertiesForReport($context);

        return $context === [] ? null : $this->technicalJson($context, true);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function legacyRecordContext(Activity $activity, array $properties): array
    {
        $label = $this->firstString($properties, [
            'new_role_name',
            'role_name',
            'new_user_name',
            'user_name',
            'new_company_name',
            'company_name',
            'new_name',
            'old_name',
            'file_name',
            'folder_name',
            'name',
        ]);
        $docNum = $this->firstString($properties, [
            'new_doc_num',
            'doc_num',
            'file_doc_num',
            'folder_doc_num',
            'role_doc_num',
            'user_doc_num',
            'company_doc_num',
        ]);
        $type = $this->legacyRecordType($activity);

        return array_filter([
            'type' => $type,
            'label' => $label,
            'doc_num' => $docNum,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function legacyRelatedContext(Activity $activity, array $properties): array
    {
        $sourceLabel = $this->firstString($properties, [
            'source_role_name',
            'source_user_name',
            'source_company_name',
            'source_name',
        ]);
        $sourceDocNum = $this->firstString($properties, ['source_doc_num']);

        if ($sourceLabel === null && $sourceDocNum === null) {
            return [];
        }

        return [
            'source' => array_filter([
                'type' => $this->legacyRecordType($activity),
                'label' => $sourceLabel,
                'doc_num' => $sourceDocNum,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    private function legacyRecordType(Activity $activity): string
    {
        $action = $this->activityKey($activity);

        if (str_starts_with($action, 'hr.')) {
            $resource = $this->actionSegment($action, 1);

            return $resource !== null ? 'hr.'.$resource : 'hr';
        }

        $resource = $this->actionSegment($action, 0);

        return $resource ?? $this->areaKey($activity);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function technicalJson(array $data, bool $pretty = false): ?string
    {
        if ($data === []) {
            return null;
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($data, $flags);

        return is_string($json) && $json !== '' ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function originalPropertiesJson(array $properties, bool $pretty = false, int $limit = self::ExportJsonLimit): ?string
    {
        $redactedProperties = $this->redactedOriginalProperties($properties);

        if ($redactedProperties === []) {
            return null;
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($redactedProperties, $flags);

        if (! is_string($json) || $json === '') {
            return null;
        }

        if (mb_strlen($json) <= $limit) {
            return $json;
        }

        $suffix = '…';

        return mb_substr($json, 0, max(1, $limit - mb_strlen($suffix))).$suffix;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function redactedOriginalProperties(array $properties): array
    {
        if ($properties === []) {
            return [];
        }

        return $this->sanitizer->redactOriginalPropertiesForReport($properties);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function safeExtraProperties(array $properties): array
    {
        $skip = [
            'id',
            'internal_id',
            'user_id',
            'auth_log_id',
            'old_locale',
            'new_locale',
            'old_doc_num',
            'new_doc_num',
            'old_doc_number',
            'new_doc_number',
            'old',
            'attributes',
            'changes',
            'record',
            'action',
            'related',
            'bulk',
            'meta',
            'changed_fields',
            'request_fields',
        ];

        $sanitized = $this->sanitizer->sanitizeForReport($properties);

        return collect($sanitized)
            ->reject(fn (mixed $value, string|int $key): bool => ! is_string($key) || in_array($key, $skip, true))
            ->reject(fn (mixed $value, string|int $key): bool => is_string($key) && ($key === 'id' || str_ends_with($key, '_id')))
            ->reject(fn (mixed $value): bool => $value === null || $value === '' || $value === [])
            ->take(12)
            ->all();
    }

    private function fieldLabel(string $field, ?string $resource = null): string
    {
        $normalized = trim($field);
        $resource = is_string($resource) && trim($resource) !== '' ? trim($resource) : null;

        if ($resource !== null) {
            $translated = $this->arrayTranslation("fields.resources.{$resource}", $normalized);

            if ($translated !== null) {
                return $translated;
            }
        }

        $translated = $this->arrayTranslation('fields.common', $normalized);

        if ($translated !== null) {
            return $translated;
        }

        $translated = $this->arrayTranslation('activity_logs.property_labels', $normalized);

        return $translated ?? Str::of(str_replace(['.', '_'], ' ', $normalized))->headline()->toString();
    }

    private function displayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? __('common.actions.yes') : __('common.actions.no');
        }

        if (is_array($value)) {
            return collect($value)
                ->map(function (mixed $item, string|int $key): string {
                    $display = $this->displayValue($item);

                    if ($display === '') {
                        return '';
                    }

                    return is_string($key) ? $this->fieldLabel($key).': '.$display : $display;
                })
                ->filter(fn (string $display): bool => $display !== '')
                ->implode(', ');
        }

        return $this->displayText($value);
    }

    private function displayValueForField(string $field, mixed $value): string
    {
        if (str_contains($field, 'locale')) {
            return $this->localeLabel($value) ?? $this->displayValue($value);
        }

        $translated = $this->translatedValue($field, $value);

        if ($translated !== null) {
            return $translated;
        }

        if ($field === 'status' || str_ends_with($field, '_status')) {
            return $this->statusValue($value) ?? $this->displayValue($value);
        }

        return $this->displayValue($value);
    }

    private function areaKey(Activity $activity): string
    {
        return $this->areaKeyForAction(
            $this->activityKey($activity),
            is_string($activity->module ?? null) ? $activity->module : null,
            is_string($activity->log_name ?? null) ? $activity->log_name : null,
        );
    }

    private function recordTypeLabel(Activity $activity): string
    {
        $action = $this->activityKey($activity);

        foreach (['roles', 'users', 'companies', 'archive', 'hr'] as $prefix) {
            if (str_starts_with($action, $prefix.'.')) {
                if ($prefix === 'hr') {
                    $resource = $this->actionSegment($action, 1);
                    $singular = $resource ? __("hr.{$resource}.singular") : null;

                    if (is_string($singular) && $singular !== "hr.{$resource}.singular") {
                        return $singular;
                    }
                }

                $translated = $this->arrayTranslation('activity_logs.record_types', $prefix);

                if ($translated !== null) {
                    return $translated;
                }
            }
        }

        if (is_string($activity->subject_type) && trim($activity->subject_type) !== '') {
            return Str::of(class_basename($activity->subject_type))->headline()->toString();
        }

        return __('activity_logs.fields.record');
    }

    private function recordTypeLabelForResource(string $resource, Activity $activity): string
    {
        $resource = trim($resource);

        if ($resource === '') {
            return $this->recordTypeLabel($activity);
        }

        if (str_starts_with($resource, 'hr.')) {
            $resourceKey = Str::after($resource, 'hr.');
            $singular = __("hr.{$resourceKey}.singular");

            if (is_string($singular) && $singular !== "hr.{$resourceKey}.singular") {
                return $singular;
            }
        }

        $translated = $this->arrayTranslation('activity_logs.record_types', $resource);

        if ($translated !== null) {
            return $translated;
        }

        return Str::of(str_replace(['.', '_'], ' ', $resource))->singular()->headline()->toString();
    }

    /**
     * @param  list<string>  $keys
     */
    private function firstString(array $properties, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->displayText($properties[$key] ?? null);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<string>
     */
    private function nestedChanges(array $properties): array
    {
        $changes = [];

        foreach (['changes'] as $key) {
            $value = $properties[$key] ?? null;

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $field => $change) {
                if (is_array($change) && (array_key_exists('old', $change) || array_key_exists('new', $change))) {
                    $changes[] = __('activity_logs.summaries.field_changed', [
                        'field' => $this->fieldLabel((string) $field),
                        'old' => $this->displayValueForField((string) $field, $change['old'] ?? null),
                        'new' => $this->displayValueForField((string) $field, $change['new'] ?? null),
                    ]);
                } elseif (is_string($field)) {
                    $changes[] = $this->fieldLabel($field).': '.$this->displayValue($change);
                }
            }
        }

        $old = $properties['old'] ?? null;
        $attributes = $properties['attributes'] ?? null;

        if (is_array($old) && is_array($attributes)) {
            foreach ($attributes as $field => $newValue) {
                if (! is_string($field) || ! array_key_exists($field, $old) || $this->isTechnicalProperty($field)) {
                    continue;
                }

                if ($old[$field] === $newValue) {
                    continue;
                }

                $changes[] = __('activity_logs.summaries.field_changed', [
                    'field' => $this->fieldLabel($field),
                    'old' => $this->displayValueForField($field, $old[$field]),
                    'new' => $this->displayValueForField($field, $newValue),
                ]);
            }
        }

        return $changes;
    }

    private function summaryKeyForActivity(string $activityKey): ?string
    {
        if (str_contains($activityKey, 'restore_blocked') || str_contains($activityKey, 'delete_blocked')) {
            return 'blocked_record';
        }

        if (str_contains($activityKey, '.create') || str_contains($activityKey, '.upload') || str_contains($activityKey, 'public_link.create')) {
            return 'created_record';
        }

        if (str_contains($activityKey, '.delete') || str_contains($activityKey, '.revoke')) {
            return 'deleted_record';
        }

        if (str_contains($activityKey, '.restore')) {
            return 'restored_record';
        }

        if (str_contains($activityKey, '.clone')) {
            return 'cloned_record';
        }

        if (str_contains($activityKey, '.update') || str_contains($activityKey, '.changed') || str_contains($activityKey, '.sync') || str_contains($activityKey, '.rename')) {
            return 'updated_record';
        }

        return null;
    }

    private function crudActivityLabel(string $key): ?string
    {
        if (str_starts_with($key, 'hr.')) {
            $resource = $this->actionSegment($key, 1);
            $verb = $this->verbKey($key, 2);
            $resourceLabel = $resource ? __("hr.{$resource}.singular") : null;

            if (is_string($resourceLabel) && $resourceLabel !== "hr.{$resource}.singular") {
                $verbLabel = $this->arrayTranslation('activity_logs.activity_verbs', $verb);

                if ($verbLabel !== null) {
                    return __('activity_logs.summaries.resource_activity', [
                        'resource' => $resourceLabel,
                        'activity' => $verbLabel,
                    ]);
                }
            }
        }

        $resource = $this->actionSegment($key, 0);
        $verb = $this->verbKey($key, 1);

        if ($resource === null || $verb === null) {
            return null;
        }

        $resourceLabel = $this->arrayTranslation('activity_logs.record_types', $resource);
        $verbLabel = $this->arrayTranslation('activity_logs.activity_verbs', $verb);

        if ($resourceLabel === null || $verbLabel === null) {
            return null;
        }

        return __('activity_logs.summaries.resource_activity', [
            'resource' => $resourceLabel,
            'activity' => $verbLabel,
        ]);
    }

    private function verbKey(string $key, int $startIndex): ?string
    {
        $parts = array_values(array_filter(explode('.', $key), fn (string $part): bool => $part !== ''));
        $verb = array_slice($parts, $startIndex);

        return $verb === [] ? null : implode('.', $verb);
    }

    private function actionSegment(string $key, int $index): ?string
    {
        $parts = array_values(array_filter(explode('.', $key), fn (string $part): bool => $part !== ''));
        $segment = $parts[$index] ?? null;

        return is_string($segment) && trim($segment) !== '' ? trim($segment) : null;
    }

    private function statusValue(mixed $status): ?string
    {
        if (! is_string($status) && ! is_numeric($status)) {
            return null;
        }

        $status = $this->displayText($status);

        if ($status === '') {
            return null;
        }

        $translated = $this->arrayTranslation('activity_logs.value_labels.statuses', $status);

        return $translated ?? Str::of(str_replace('_', ' ', $status))->headline()->toString();
    }

    private function translatedValue(string $field, mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value) && ! is_bool($value)) {
            return null;
        }

        $key = is_bool($value) ? ($value ? 'true' : 'false') : $this->displayText($value);

        if ($key === '') {
            return null;
        }

        return $this->arrayTranslation("values.{$field}", $key)
            ?? $this->arrayTranslation('values.common', $key);
    }

    private function isTechnicalProperty(string $key): bool
    {
        return $key === 'id'
            || $key === 'internal_id'
            || $key === 'auth_log_id'
            || str_ends_with($key, '_id')
            || str_contains($key, 'password')
            || str_contains($key, 'token')
            || str_contains($key, 'secret');
    }

    private function localeLabel(mixed $locale): ?string
    {
        if (! is_string($locale) && ! is_numeric($locale)) {
            return null;
        }

        $locale = $this->displayText($locale);

        if ($locale === '') {
            return null;
        }

        return config("languages.available.{$locale}.native")
            ?: config("languages.available.{$locale}.name")
            ?: mb_strtoupper($locale);
    }

    private function joinLabel(mixed $name, mixed $docNum): string
    {
        $parts = array_values(array_filter([
            $this->displayText($name),
            $this->displayText($docNum),
        ], fn (string $value): bool => $value !== ''));

        return $parts === [] ? '' : implode(' / ', $parts);
    }

    private function displayText(mixed $value): string
    {
        return $this->sanitizer->displayText($value);
    }

    private function arrayTranslation(string $root, string $key): ?string
    {
        $translations = __($root);

        if (! is_array($translations)) {
            return null;
        }

        if (array_key_exists($key, $translations) && is_string($translations[$key]) && $translations[$key] !== '') {
            return $translations[$key];
        }

        $value = data_get($translations, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
