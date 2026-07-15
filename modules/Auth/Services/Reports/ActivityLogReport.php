<?php

namespace Modules\Auth\Services\Reports;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Company;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\Reports\ActivityLogHumanizer;
use Spatie\Activitylog\Models\Activity;

class ActivityLogReport
{
    public function __construct(
        private readonly ActivityLogHumanizer $humanizer,
        private readonly DateFormatService $dates,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Activity>
     */
    public function query(array $filters = [], bool $listing = false): Builder
    {
        $table = config('activitylog.table_name', 'activity_log');

        $query = Activity::query()
            ->select($listing ? $this->listingColumns($table) : ["{$table}.*"])
            ->addSelect([
                'causer_name' => User::query()
                    ->select('name')
                    ->whereColumn('users.id', "{$table}.causer_id")
                    ->where("{$table}.causer_type", User::class)
                    ->limit(1),
                'causer_doc_num' => User::query()
                    ->select('doc_num')
                    ->whereColumn('users.id', "{$table}.causer_id")
                    ->where("{$table}.causer_type", User::class)
                    ->limit(1),
                'company_name' => Company::query()
                    ->select('name')
                    ->whereColumn('companies.id', "{$table}.company_id")
                    ->limit(1),
                'company_doc_num' => Company::query()
                    ->select('doc_num')
                    ->whereColumn('companies.id', "{$table}.company_id")
                    ->limit(1),
            ]);

        return $this->applyFilters($query, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Activity>
     */
    public function listingQuery(array $filters = []): Builder
    {
        return $this->query($filters, listing: true);
    }

    /**
     * @return list<string>
     */
    private function listingColumns(string $table): array
    {
        return [
            "{$table}.id",
            "{$table}.public_id",
            "{$table}.log_name",
            "{$table}.description",
            "{$table}.event",
            "{$table}.subject_type",
            "{$table}.subject_id",
            "{$table}.causer_type",
            "{$table}.causer_id",
            "{$table}.properties",
            "{$table}.batch_uuid",
            "{$table}.company_id",
            "{$table}.module",
            "{$table}.action",
            "{$table}.status",
            "{$table}.ip_address",
            "{$table}.url",
            "{$table}.method",
            "{$table}.created_at",
            "{$table}.updated_at",
        ];
    }

    /**
     * @param  Builder<Activity>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Activity>
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        $table = config('activitylog.table_name', 'activity_log');

        $this->dateRange($query, $filters, "{$table}.created_at");

        foreach (['action', 'event', 'status', 'method'] as $field) {
            $value = $this->stringFilter($filters[$field] ?? null);

            if ($value !== null) {
                $query->where("{$table}.{$field}", $value);
            }
        }

        $area = $this->stringFilter($filters['area'] ?? $filters['module'] ?? null);

        if ($area !== null) {
            $this->applyAreaFilter($query, $area, $table);
        }

        $ip = $this->stringFilter($filters['ip'] ?? null);

        if ($ip !== null) {
            $query->where("{$table}.ip_address", 'LIKE', "%{$ip}%");
        }

        $causer = $this->stringFilter($filters['causer'] ?? null);

        if ($causer !== null) {
            $query->whereExists(function ($query) use ($causer, $table): void {
                $query->selectRaw('1')
                    ->from('users')
                    ->whereColumn('users.id', "{$table}.causer_id")
                    ->where("{$table}.causer_type", User::class)
                    ->where(function ($query) use ($causer): void {
                        $query->where('users.doc_num', $causer)
                            ->orWhere('users.name', 'LIKE', "%{$causer}%")
                            ->orWhere('users.username', 'LIKE', "%{$causer}%")
                            ->orWhere('users.email', 'LIKE', "%{$causer}%");
                    });
            });
        }

        $company = $this->stringFilter($filters['company'] ?? null);

        if ($company !== null) {
            $query->whereExists(function ($query) use ($company, $table): void {
                $query->selectRaw('1')
                    ->from('companies')
                    ->whereColumn('companies.id', "{$table}.company_id")
                    ->where(function ($query) use ($company): void {
                        $query->where('companies.doc_num', $company)
                            ->orWhere('companies.name', 'LIKE', "%{$company}%");
                    });
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function filtersFromRequest(Request $request): array
    {
        return $request->only([
            'date_from',
            'date_to',
            'area',
            'action',
            'event',
            'status',
            'causer',
            'company',
            'ip',
            'method',
        ]);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->humanizer->headings();
    }

    /**
     * @return list<string>
     */
    public function map(Activity $activity): array
    {
        return $this->humanizer->exportMap($activity);
    }

    /**
     * @return list<string>
     */
    public function pdfMap(Activity $activity): array
    {
        return $this->humanizer->exportMap($activity, forPdf: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function details(Activity $activity): array
    {
        return $this->humanizer->details($activity);
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function userOptions(Request $request): array
    {
        $term = $this->stringFilter($request->input('q')) ?? '';
        $table = config('activitylog.table_name', 'activity_log');

        $users = User::query()
            ->select(['doc_num', 'name', 'username', 'email'])
            ->whereExists(function ($query) use ($table): void {
                $query->selectRaw('1')
                    ->from($table)
                    ->whereColumn("{$table}.causer_id", 'users.id')
                    ->where("{$table}.causer_type", User::class);
            })
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('name', 'LIKE', "%{$term}%")
                        ->orWhere('doc_num', 'LIKE', "%{$term}%")
                        ->orWhere('username', 'LIKE', "%{$term}%")
                        ->orWhere('email', 'LIKE', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->limit(25)
            ->get();

        return $this->select2Response($users->map(fn (User $user): array => [
            'id' => (string) $user->doc_num,
            'text' => trim($user->name.' / '.$user->doc_num.($user->username ? ' / '.$user->username : '')),
        ])->all());
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function companyOptions(Request $request): array
    {
        $term = $this->stringFilter($request->input('q')) ?? '';
        $table = config('activitylog.table_name', 'activity_log');

        $companies = Company::query()
            ->select(['doc_num', 'name'])
            ->whereExists(function ($query) use ($table): void {
                $query->selectRaw('1')
                    ->from($table)
                    ->whereColumn("{$table}.company_id", 'companies.id');
            })
            ->when($term !== '', function (Builder $query) use ($term): void {
                $query->where(function (Builder $query) use ($term): void {
                    $query->where('name', 'LIKE', "%{$term}%")
                        ->orWhere('doc_num', 'LIKE', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->limit(25)
            ->get();

        return $this->select2Response($companies->map(fn (Company $company): array => [
            'id' => (string) $company->doc_num,
            'text' => trim($company->name.' / '.$company->doc_num),
        ])->all());
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function actionOptions(Request $request): array
    {
        $term = mb_strtolower($this->stringFilter($request->input('q')) ?? '');
        $table = config('activitylog.table_name', 'activity_log');

        $actions = DB::table($table)
            ->select('action')
            ->whereNotNull('action')
            ->distinct()
            ->orderBy('action')
            ->limit(200)
            ->pluck('action')
            ->filter(fn (mixed $action): bool => is_string($action) && trim($action) !== '')
            ->map(fn (string $action): array => [
                'id' => $action,
                'text' => $this->humanizer->activityLabel($action),
            ])
            ->filter(fn (array $option): bool => $term === '' || str_contains(mb_strtolower($option['text'].' '.$option['id']), $term))
            ->values()
            ->take(25)
            ->all();

        return $this->select2Response($actions);
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function statusOptions(Request $request): array
    {
        $term = mb_strtolower($this->stringFilter($request->input('q')) ?? '');
        $table = config('activitylog.table_name', 'activity_log');

        $statuses = DB::table($table)
            ->select('status')
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->filter(fn (mixed $status): bool => is_string($status) && trim($status) !== '')
            ->map(fn (string $status): array => [
                'id' => $status,
                'text' => $this->humanizer->statusLabel($status),
            ])
            ->filter(fn (array $option): bool => $term === '' || str_contains(mb_strtolower($option['text'].' '.$option['id']), $term))
            ->values()
            ->all();

        return $this->select2Response($statuses);
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function areaOptions(Request $request): array
    {
        $term = mb_strtolower($this->stringFilter($request->input('q')) ?? '');
        $table = config('activitylog.table_name', 'activity_log');

        $areas = DB::table($table)
            ->select(['action', 'module', 'log_name'])
            ->distinct()
            ->orderBy('action')
            ->limit(500)
            ->get()
            ->map(function (object $row): array {
                $key = $this->humanizer->areaKeyForAction(
                    is_string($row->action ?? null) ? $row->action : '',
                    is_string($row->module ?? null) ? $row->module : null,
                    is_string($row->log_name ?? null) ? $row->log_name : null,
                );

                return [
                    'id' => $key,
                    'text' => $this->humanizer->areaLabel($key),
                ];
            })
            ->unique('id')
            ->filter(fn (array $option): bool => $term === '' || str_contains(mb_strtolower($option['text'].' '.$option['id']), $term))
            ->values()
            ->take(25)
            ->all();

        return $this->select2Response($areas);
    }

    /**
     * @param  Builder<Activity>  $query
     * @param  array<string, mixed>  $filters
     */
    private function dateRange(Builder $query, array $filters, string $column): void
    {
        $from = $this->dateFilter($filters['date_from'] ?? null);
        $to = $this->dateFilter($filters['date_to'] ?? null);

        if ($from instanceof Carbon) {
            $query->where($column, '>=', $from->startOfDay());
        }

        if ($to instanceof Carbon) {
            $query->where($column, '<=', $to->endOfDay());
        }
    }

    private function dateFilter(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->dates->parseDate($value);
    }

    private function stringFilter(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  Builder<Activity>  $query
     */
    private function applyAreaFilter(Builder $query, string $area, string $table): void
    {
        $query->where(function (Builder $query) use ($area, $table): void {
            match (true) {
                $area === 'archive' => $query
                    ->where("{$table}.action", 'LIKE', 'archive.%')
                    ->orWhere("{$table}.action", 'LIKE', 'file_manager.%'),
                $area === 'language_settings' => $query->where("{$table}.action", 'LIKE', 'language.%'),
                $area === 'sessions' => $query
                    ->where("{$table}.action", 'LIKE', 'auth.sessions.%')
                    ->orWhere("{$table}.action", 'LIKE', 'sessions.%')
                    ->orWhere("{$table}.action", 'LIKE', 'lock_screen.%'),
                str_starts_with($area, 'hr_') => $query->where("{$table}.action", 'LIKE', 'hr.'.substr($area, 3).'.%'),
                in_array($area, ['roles', 'users', 'companies', 'profile'], true) => $query->where("{$table}.action", 'LIKE', "{$area}.%"),
                default => $query
                    ->where("{$table}.module", $area)
                    ->orWhere("{$table}.log_name", $area)
                    ->orWhere("{$table}.action", 'LIKE', "{$area}.%"),
            };
        });
    }

    /**
     * @param  list<array{id: string, text: string}>  $results
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    private function select2Response(array $results): array
    {
        return [
            'results' => $results,
            'pagination' => ['more' => false],
        ];
    }
}
