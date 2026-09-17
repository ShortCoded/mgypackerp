<?php

namespace Modules\Core\Services\Reports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Core\Models\Currency;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\Select2ResponseService;
use Modules\HR\Models\HrArea;
use Modules\HR\Models\HrCity;
use Modules\HR\Models\HrCountry;
use Modules\HR\Models\HrGovernorate;

abstract class BusinessPartnerDataReport
{
    /**
     * @var list<string>
     */
    public const ColumnKeys = [
        'doc_num',
        'name',
        'account_group',
        'account',
        'phone',
        'mobile',
        'email',
        'contact_person',
        'address',
        'country',
        'governorate',
        'city',
        'area',
        'tax_number',
        'commercial_register',
        'credit_limits',
        'status',
        'created_at',
    ];

    /**
     * @var list<string>
     */
    public const FilterKeys = [
        'doc_num',
        'name',
        'phone',
        'account_group_doc_num',
        'account_doc_num',
        'country_doc_num',
        'governorate_doc_num',
        'city_doc_num',
        'area_doc_num',
        'data_completeness',
        'status',
        'created_from',
        'created_to',
    ];

    public function __construct(
        private readonly OperatingCompanyContextService $companyContext,
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
        private readonly DateFormatService $dates,
        private readonly NumericFormatService $numericFormatter,
    ) {}

    /**
     * @return class-string<Model>
     */
    abstract protected function modelClass(): string;

    /**
     * @return class-string<Model>
     */
    abstract protected function creditLimitModelClass(): string;

    abstract protected function creditLimitForeignKey(): string;

    abstract public function reportKey(): string;

    abstract public function partnerTranslationKey(): string;

    abstract public function routePrefix(): string;

    abstract public function permissionPrefix(): string;

    abstract public function filenamePrefix(): string;

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    public function listingQuery(array $filters = []): Builder
    {
        $modelClass = $this->modelClass();
        $table = $this->partnerTable();
        $companyId = $this->companyContext->currentCompanyId();
        $query = $this->companyContext->applyCompanyScope($modelClass::query(), $table);

        $query
            ->leftJoin($this->accountTable().' as partner_accounts', function ($join) use ($table): void {
                $join
                    ->on('partner_accounts.id', '=', "{$table}.account_id")
                    ->on('partner_accounts.company_id', '=', "{$table}.company_id");
            })
            ->leftJoin($this->accountTable().' as partner_account_groups', function ($join) use ($table): void {
                $join
                    ->on('partner_account_groups.id', '=', "{$table}.account_group_id")
                    ->on('partner_account_groups.company_id', '=', "{$table}.company_id");
            })
            ->leftJoin($this->countryTable().' as partner_countries', 'partner_countries.id', '=', "{$table}.country_id")
            ->leftJoin($this->governorateTable().' as partner_governorates', 'partner_governorates.id', '=', "{$table}.governorate_id")
            ->leftJoin($this->cityTable().' as partner_cities', 'partner_cities.id', '=', "{$table}.city_id")
            ->leftJoin($this->areaTable().' as partner_areas', 'partner_areas.id', '=', "{$table}.area_id")
            ->select($this->listingColumns())
            ->with([
                'creditLimits' => function ($query) use ($companyId): void {
                    $query
                        ->select([
                            'id',
                            $this->creditLimitForeignKey(),
                            'currency_id',
                            'credit_limit',
                        ])
                        ->where('company_id', $companyId ?? 0)
                        ->orderBy('currency_id')
                        ->with(['currency' => fn ($query) => $query
                            ->select(['id', 'doc_num', 'code', 'name'])
                            ->where('company_id', $companyId ?? 0)]);
                },
            ]);

        return $this->applyFilters($query, $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    public function orderedQuery(array $filters = []): Builder
    {
        $table = $this->partnerTable();

        return $this->listingQuery($filters)
            ->orderByDesc("{$table}.doc_number")
            ->orderByDesc("{$table}.id");
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, Model>, limited: bool}
     */
    public function pdfResult(array $filters = []): array
    {
        return [
            'rows' => $this->orderedQuery($filters)->get(),
            'limited' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filtersFromRequest(Request $request): array
    {
        $filters = collect($request->only(self::FilterKeys))
            ->map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value)
            ->filter(fn (mixed $value): bool => is_array($value) || trim((string) $value) !== '')
            ->all();

        if (! in_array($filters['status'] ?? null, ['active', 'inactive'], true)) {
            unset($filters['status']);
        }

        if (! in_array($filters['data_completeness'] ?? null, [
            'complete',
            'any_issue',
            'missing_location',
            'missing_address',
            'missing_contact',
            'legacy_unlinked_location',
        ], true)) {
            unset($filters['data_completeness']);
        }

        foreach (['created_from', 'created_to'] as $dateFilter) {
            if (isset($filters[$dateFilter]) && ! $this->dates->isValidDate((string) $filters[$dateFilter])) {
                unset($filters[$dateFilter]);
            }
        }

        return $filters;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return array_values($this->fieldLabels());
    }

    /**
     * @return list<string>
     */
    public function map(Model $row): array
    {
        $display = $this->row($row);

        return array_map(
            fn (string $column): string => (string) ($display[$column] ?? ''),
            self::ColumnKeys,
        );
    }

    /**
     * @return list<string>
     */
    public function pdfHeadings(): array
    {
        $fields = $this->fieldLabels();

        return [
            $fields['doc_num'],
            $fields['name'],
            $fields['account_group'],
            __('business_partner_reports.pdf.contact'),
            $fields['email'],
            __('business_partner_reports.pdf.location'),
            $fields['tax_number'],
            $fields['account'],
            $fields['credit_limits'],
            $fields['status'],
            $fields['created_at'],
        ];
    }

    /**
     * @return list<string>
     */
    public function pdfMap(Model $row): array
    {
        $display = $this->row($row);

        return [
            $display['doc_num'],
            $display['name'],
            $display['account_group'],
            $this->joinLabels([$display['phone'], $display['mobile']]),
            $display['email'],
            $this->joinLabels([$display['area'], $display['city'], $display['governorate'], $display['country']]),
            $display['tax_number'],
            $display['account'],
            $display['credit_limits'],
            $display['status'],
            $display['created_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function row(Model $row): array
    {
        return [
            'doc_num' => $this->plainText($row->doc_num),
            'name' => $this->plainText($row->name),
            'account_group' => $this->accountLabel($row->account_group_code, $row->account_group_name, $row->account_group_name_en),
            'account' => $this->accountLabel($row->account_code, $row->account_name, $row->account_name_en),
            'phone' => $this->plainText($row->phone),
            'mobile' => $this->plainText($row->mobile),
            'email' => $this->plainText($row->email),
            'contact_person' => $this->plainText($row->contact_person),
            'address' => $this->plainText($row->address),
            'country' => $this->locationLabel($row->country_name, $row->legacy_country),
            'governorate' => $this->locationLabel($row->governorate_name, $row->legacy_governorate),
            'city' => $this->locationLabel($row->city_name, $row->legacy_city),
            'area' => $this->plainText($row->area_name),
            'tax_number' => $this->plainText($row->tax_number),
            'commercial_register' => $this->plainText($row->commercial_register),
            'credit_limits' => $this->creditLimitsLabel($row),
            'status' => $this->statusLabel($row->status),
            'created_at' => $this->dates->formatDateTime($row->created_at, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    public function filterSummary(array $filters): array
    {
        $labels = [];

        foreach ($this->filterLabels() as $key => $label) {
            $value = $this->summaryFilterValue($key, $filters[$key] ?? null);

            if ($value !== '') {
                $labels[] = "{$label}: {$value}";
            }
        }

        return $labels;
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function accountOptions(Request $request): array
    {
        return $this->accountSelectOptions($request, 'account_id');
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function accountGroupOptions(Request $request): array
    {
        return $this->accountSelectOptions($request, 'account_group_id');
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>, exists: list<array<string, mixed>>}
     */
    public function searchColumns(): array
    {
        $table = $this->partnerTable();
        $creditLimitTable = $this->creditLimitTable();

        return [
            'text' => [
                "{$table}.doc_num",
                "{$table}.name",
                "{$table}.phone",
                "{$table}.mobile",
                "{$table}.email",
                "{$table}.contact_person",
                "{$table}.address",
                "{$table}.tax_number",
                "{$table}.commercial_register",
                'partner_accounts.doc_num',
                'partner_accounts.account_code',
                'partner_accounts.name',
                'partner_accounts.name_en',
                'partner_account_groups.doc_num',
                'partner_account_groups.account_code',
                'partner_account_groups.name',
                'partner_account_groups.name_en',
                'partner_countries.name',
                'partner_governorates.name',
                'partner_cities.name',
                'partner_areas.name',
            ],
            'dates' => ["{$table}.created_at"],
            'date_text' => ["{$table}.created_at"],
            'exists' => [[
                'table' => $creditLimitTable,
                'first' => "{$creditLimitTable}.{$this->creditLimitForeignKey()}",
                'second' => "{$table}.id",
                'where' => [
                    ["{$creditLimitTable}.company_id", $this->companyContext->currentCompanyId() ?? 0],
                    ["{$creditLimitTable}.deleted_at", null],
                    [$this->currencyTable().'.company_id', $this->companyContext->currentCompanyId() ?? 0],
                    [$this->currencyTable().'.deleted_at', null],
                ],
                'join' => [
                    'table' => $this->currencyTable(),
                    'first' => $this->currencyTable().'.id',
                    'second' => "{$creditLimitTable}.currency_id",
                ],
                'columns' => [$this->currencyTable().'.code', $this->currencyTable().'.name'],
            ]],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function fieldLabels(): array
    {
        $key = $this->partnerTranslationKey();

        return [
            'doc_num' => __("{$key}.attributes.doc_num"),
            'name' => __("{$key}.attributes.name"),
            'account_group' => __("{$key}.attributes.account_group"),
            'account' => __("{$key}.attributes.account"),
            'phone' => __("{$key}.attributes.phone"),
            'mobile' => __("{$key}.attributes.mobile"),
            'email' => __("{$key}.attributes.email"),
            'contact_person' => __("{$key}.attributes.contact_person"),
            'address' => __("{$key}.attributes.address"),
            'country' => __("{$key}.attributes.country"),
            'governorate' => __("{$key}.attributes.governorate"),
            'city' => __("{$key}.attributes.city"),
            'area' => __("{$key}.attributes.area"),
            'tax_number' => __("{$key}.attributes.tax_number"),
            'commercial_register' => __("{$key}.attributes.commercial_register"),
            'credit_limits' => __("{$key}.attributes.credit_limits"),
            'status' => __("{$key}.attributes.status"),
            'created_at' => __("{$key}.columns.created_at"),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function filterLabels(): array
    {
        $fields = $this->fieldLabels();

        return [
            'doc_num' => $fields['doc_num'],
            'name' => $fields['name'],
            'phone' => __('business_partner_reports.filters.phone_or_mobile'),
            'account_group_doc_num' => $fields['account_group'],
            'account_doc_num' => $fields['account'],
            'country_doc_num' => $fields['country'],
            'governorate_doc_num' => $fields['governorate'],
            'city_doc_num' => $fields['city'],
            'area_doc_num' => $fields['area'],
            'data_completeness' => __('business_partner_reports.filters.data_completeness'),
            'status' => $fields['status'],
            'created_from' => __('business_partner_reports.filters.created_from'),
            'created_to' => __('business_partner_reports.filters.created_to'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pageData(): array
    {
        return [
            'key' => $this->reportKey(),
            'title' => $this->reportTitle(),
            'description' => __("business_partner_reports.{$this->reportKey()}.description"),
            'table_title' => __("business_partner_reports.{$this->reportKey()}.table_title"),
            'field_labels' => $this->fieldLabels(),
            'filter_labels' => $this->filterLabels(),
            'route_prefix' => $this->routePrefix(),
            'permission_prefix' => $this->permissionPrefix(),
        ];
    }

    public function reportTitle(): string
    {
        return __("business_partner_reports.{$this->reportKey()}.title");
    }

    public function companyName(): string
    {
        return $this->plainText($this->companyContext->currentCompany()?->name);
    }

    public function routeName(string $suffix): string
    {
        return $this->routePrefix().'.'.$suffix;
    }

    public function permission(string $action): string
    {
        return $this->permissionPrefix().'.'.$action;
    }

    public function filename(string $extension): string
    {
        return $this->filenamePrefix().'-'.now()->toDateString().'.'.$extension;
    }

    public function generatedAtLabel(): string
    {
        return $this->dates->formatDateTime(now(), '');
    }

    public function isRtl(): bool
    {
        return config('languages.available.'.app()->getLocale().'.dir', 'ltr') === 'rtl';
    }

    public function partnerTable(): string
    {
        $modelClass = $this->modelClass();

        return (new $modelClass)->getTable();
    }

    /**
     * @return list<string>
     */
    private function listingColumns(): array
    {
        $table = $this->partnerTable();

        return [
            "{$table}.id",
            "{$table}.company_id",
            "{$table}.doc_number",
            "{$table}.doc_num",
            "{$table}.name",
            "{$table}.status",
            "{$table}.phone",
            "{$table}.mobile",
            "{$table}.email",
            "{$table}.contact_person",
            "{$table}.address",
            "{$table}.tax_number",
            "{$table}.commercial_register",
            "{$table}.country as legacy_country",
            "{$table}.governorate as legacy_governorate",
            "{$table}.city as legacy_city",
            "{$table}.created_at",
            'partner_accounts.doc_num as account_doc_num',
            'partner_accounts.account_code',
            'partner_accounts.name as account_name',
            'partner_accounts.name_en as account_name_en',
            'partner_account_groups.doc_num as account_group_doc_num',
            'partner_account_groups.account_code as account_group_code',
            'partner_account_groups.name as account_group_name',
            'partner_account_groups.name_en as account_group_name_en',
            'partner_countries.name as country_name',
            'partner_governorates.name as governorate_name',
            'partner_cities.name as city_name',
            'partner_areas.name as area_name',
        ];
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Model>
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $table = $this->partnerTable();

        foreach ([
            'doc_num' => ["{$table}.doc_num"],
            'name' => ["{$table}.name"],
            'phone' => ["{$table}.phone", "{$table}.mobile"],
        ] as $filter => $columns) {
            $value = $this->stringFilter($filters[$filter] ?? null);

            if ($value !== null) {
                $this->searchService->applyMultiTermSearch($query, [$value], ['text' => $columns]);
            }
        }

        foreach ([
            'account_doc_num' => 'partner_accounts.doc_num',
            'account_group_doc_num' => 'partner_account_groups.doc_num',
            'country_doc_num' => 'partner_countries.doc_num',
            'governorate_doc_num' => 'partner_governorates.doc_num',
            'city_doc_num' => 'partner_cities.doc_num',
            'area_doc_num' => 'partner_areas.doc_num',
        ] as $filter => $column) {
            $value = $this->stringFilter($filters[$filter] ?? null);

            if ($value !== null) {
                $query->where($column, $value);
            }
        }

        $status = $this->stringFilter($filters['status'] ?? null);

        if ($status !== null && in_array($status, ['active', 'inactive'], true)) {
            $query->where("{$table}.status", $status);
        }

        $this->applyDataCompletenessFilter(
            $query,
            $table,
            $this->stringFilter($filters['data_completeness'] ?? null),
        );

        $from = $this->dates->parseDate($this->stringFilter($filters['created_from'] ?? null));
        $to = $this->dates->parseDate($this->stringFilter($filters['created_to'] ?? null));

        if ($from) {
            $query->where("{$table}.created_at", '>=', $from);
        }

        if ($to) {
            $query->where("{$table}.created_at", '<=', $to->copy()->endOfDay());
        }

        return $query;
    }

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    private function accountSelectOptions(Request $request, string $partnerColumn): array
    {
        $companyId = $this->companyContext->requireCompanyId($request);
        $accountTable = $this->accountTable();
        $partnerTable = $this->partnerTable();
        $selectedDocNum = $request->string('selected_doc_num')->trim()->toString();
        $query = Account::withTrashed()
            ->where("{$accountTable}.company_id", $companyId)
            ->whereExists(function ($query) use ($companyId, $accountTable, $partnerColumn, $partnerTable): void {
                $query
                    ->selectRaw('1')
                    ->from($partnerTable)
                    ->whereColumn("{$partnerTable}.{$partnerColumn}", "{$accountTable}.id")
                    ->where("{$partnerTable}.company_id", $companyId)
                    ->whereNull("{$partnerTable}.deleted_at");
            })
            ->select([
                "{$accountTable}.doc_num",
                "{$accountTable}.doc_number",
                "{$accountTable}.account_code",
                "{$accountTable}.name",
                "{$accountTable}.name_en",
            ])
            ->orderByRaw("LENGTH({$accountTable}.account_code), {$accountTable}.account_code");

        if ($selectedDocNum !== '') {
            $selected = (clone $query)->where("{$accountTable}.doc_num", $selectedDocNum)->first();

            return [
                'results' => $selected instanceof Account ? [$this->accountOption($selected)] : [],
                'pagination' => ['more' => false],
            ];
        }

        $terms = $this->searchService->terms($request->input('q', $request->input('term')));

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    "{$accountTable}.doc_num",
                    "{$accountTable}.account_code",
                    "{$accountTable}.name",
                    "{$accountTable}.name_en",
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (Account $account): array => $this->accountOption($account));
    }

    /**
     * @return array{id: string, text: string}
     */
    private function accountOption(Account $account): array
    {
        return [
            'id' => (string) $account->doc_num,
            'text' => $this->accountLabel($account->account_code, $account->name, $account->name_en),
        ];
    }

    private function creditLimitsLabel(Model $row): string
    {
        if (! $row->relationLoaded('creditLimits')) {
            return '';
        }

        return $row->getRelation('creditLimits')
            ->map(function (Model $limit): string {
                $currency = $limit->relationLoaded('currency') ? $limit->getRelation('currency') : null;
                $currencyLabel = $currency instanceof Model
                    ? $this->joinLabels([$currency->code, $currency->name])
                    : '';
                $amount = $this->amountLabel($limit->credit_limit);

                return $currencyLabel === '' ? $amount : "{$currencyLabel}: {$amount}";
            })
            ->filter()
            ->implode(' | ');
    }

    private function amountLabel(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        return $this->numericFormatter->format($value);
    }

    private function accountLabel(mixed $code, mixed $name, mixed $nameEn): string
    {
        $displayName = $this->isRtl()
            ? $this->plainText($name)
            : ($this->plainText($nameEn) ?: $this->plainText($name));

        return $this->joinLabels([$code, $displayName]);
    }

    private function locationLabel(mixed $lookupName, mixed $legacyName): string
    {
        return $this->plainText($lookupName) ?: $this->plainText($legacyName);
    }

    private function statusLabel(mixed $status): string
    {
        $status = $this->plainText($status);
        $key = "business_partners.statuses.{$status}";

        return $status !== '' && trans()->has($key) ? __($key) : $status;
    }

    private function summaryFilterValue(string $key, mixed $value): string
    {
        $value = $this->stringFilter($value);

        if ($value === null) {
            return '';
        }

        if ($key === 'status') {
            return $this->statusLabel($value);
        }

        if ($key === 'data_completeness') {
            $translationKey = "business_partner_reports.completeness.{$value}";

            return trans()->has($translationKey) ? __($translationKey) : $this->plainText($value);
        }

        return $this->plainText($value);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyDataCompletenessFilter(Builder $query, string $table, ?string $filter): void
    {
        if ($filter === null) {
            return;
        }

        $missingLocation = function (Builder $query) use ($table): void {
            $query
                ->whereNull("{$table}.country_id")
                ->orWhereNull("{$table}.governorate_id")
                ->orWhereNull("{$table}.city_id");
        };
        $missingAddress = fn (Builder $query): Builder => $query->whereRaw(
            "NULLIF(TRIM(COALESCE({$table}.address, '')), '') IS NULL"
        );
        $missingContact = function (Builder $query) use ($table): void {
            $query
                ->whereRaw("NULLIF(TRIM(COALESCE({$table}.phone, '')), '') IS NULL")
                ->whereRaw("NULLIF(TRIM(COALESCE({$table}.mobile, '')), '') IS NULL")
                ->whereRaw("NULLIF(TRIM(COALESCE({$table}.email, '')), '') IS NULL");
        };
        $legacyUnlinked = function (Builder $query) use ($table): void {
            foreach ([
                'country_id' => 'country',
                'governorate_id' => 'governorate',
                'city_id' => 'city',
            ] as $idColumn => $legacyColumn) {
                $query->orWhere(function (Builder $query) use ($idColumn, $legacyColumn, $table): void {
                    $query
                        ->whereNull("{$table}.{$idColumn}")
                        ->whereRaw("NULLIF(TRIM(COALESCE({$table}.{$legacyColumn}, '')), '') IS NOT NULL");
                });
            }
        };

        match ($filter) {
            'complete' => $query
                ->whereNotNull("{$table}.country_id")
                ->whereNotNull("{$table}.governorate_id")
                ->whereNotNull("{$table}.city_id")
                ->whereRaw("NULLIF(TRIM(COALESCE({$table}.address, '')), '') IS NOT NULL")
                ->where(function (Builder $query) use ($table): void {
                    $query
                        ->whereRaw("NULLIF(TRIM(COALESCE({$table}.phone, '')), '') IS NOT NULL")
                        ->orWhereRaw("NULLIF(TRIM(COALESCE({$table}.mobile, '')), '') IS NOT NULL")
                        ->orWhereRaw("NULLIF(TRIM(COALESCE({$table}.email, '')), '') IS NOT NULL");
                }),
            'missing_location' => $query->where($missingLocation),
            'missing_address' => $query->where($missingAddress),
            'missing_contact' => $query->where($missingContact),
            'legacy_unlinked_location' => $query->where($legacyUnlinked),
            'any_issue' => $query->where(function (Builder $query) use ($legacyUnlinked, $missingAddress, $missingContact, $missingLocation): void {
                $query
                    ->where($missingLocation)
                    ->orWhere($missingAddress)
                    ->orWhere($missingContact)
                    ->orWhere($legacyUnlinked);
            }),
            default => null,
        };
    }

    /**
     * @param  list<mixed>  $values
     */
    private function joinLabels(array $values): string
    {
        return implode(' / ', array_values(array_filter(
            array_map(fn (mixed $value): string => $this->plainText($value), $values),
            fn (string $value): bool => $value !== '',
        )));
    }

    private function plainText(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        return Str::squish(strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function stringFilter(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function creditLimitTable(): string
    {
        $modelClass = $this->creditLimitModelClass();

        return (new $modelClass)->getTable();
    }

    private function accountTable(): string
    {
        return (new Account)->getTable();
    }

    private function currencyTable(): string
    {
        return (new Currency)->getTable();
    }

    private function countryTable(): string
    {
        return (new HrCountry)->getTable();
    }

    private function governorateTable(): string
    {
        return (new HrGovernorate)->getTable();
    }

    private function cityTable(): string
    {
        return (new HrCity)->getTable();
    }

    private function areaTable(): string
    {
        return (new HrArea)->getTable();
    }
}
