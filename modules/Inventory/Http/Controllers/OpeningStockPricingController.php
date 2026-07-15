<?php

namespace Modules\Inventory\Http\Controllers;

use App\Models\User;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Finance\Services\FinanceDocumentNumberSettingsService;
use Modules\Inventory\DataTables\OpeningStockPricingsDataTable;
use Modules\Inventory\Http\Requests\OpeningStockPricings\StoreOpeningStockPricingRequest;
use Modules\Inventory\Http\Requests\OpeningStockPricings\UpdateOpeningStockPricingDocumentNumberSettingsRequest;
use Modules\Inventory\Http\Requests\OpeningStockPricings\UpdateOpeningStockPricingRequest;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\OpeningStockPricingLine;
use Modules\Inventory\Services\InventorySelect2Service;
use Modules\Inventory\Services\OpeningStockPricingService;

class OpeningStockPricingController extends Controller
{
    public function __construct(
        private readonly OpeningStockPricingService $service,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly OperatingContextService $operatingContext,
    ) {}

    public function index(Request $request, FinanceDocumentNumberSettingsService $settings): View
    {
        return view('modules.inventory.opening-stock-pricings.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.inventory.opening-stock-pricings.index'),
            'documentNumberSettings' => $settings->current('inventory_opening_stock_pricings'),
        ]);
    }

    public function data(Request $request, OpeningStockPricingsDataTable $dataTable): JsonResponse
    {
        return $dataTable->json($request);
    }

    public function create(Request $request): View
    {
        return $this->form($request, 'create');
    }

    public function show(Request $request, string $openingStockPricing): View
    {
        $record = $this->findInCurrentContext($request, $openingStockPricing, true);
        abort_if($record->trashed() && ! $request->user()?->can('inventory.opening_stock_pricings.view_trashed'), 404);

        return $this->form($request, 'view', $record);
    }

    public function edit(Request $request, string $openingStockPricing): View
    {
        $record = $this->findInCurrentContext($request, $openingStockPricing);
        abort_if($record->isLockedForEditing(), 403, __('inventory.opening_stock_pricings.messages.closed_edit_forbidden'));

        return $this->form($request, 'edit', $record);
    }

    public function clone(Request $request, string $openingStockPricing): View
    {
        return $this->form($request, 'clone', $this->findInCurrentContext($request, $openingStockPricing), (string) Str::uuid());
    }

    public function store(StoreOpeningStockPricingRequest $request): JsonResponse
    {
        $record = $this->guardDomain(fn (): OpeningStockPricing => $this->service->create($request->validated())['record']);

        $message = $this->submitAction($request, true) === 'save'
            ? __('inventory.opening_stock_pricings.messages.saved_and_new')
            : __('inventory.opening_stock_pricings.messages.created');

        return response()->json([
            'success' => true,
            'message' => $message,
            ...$this->saveResponse($request, $record, 'store'),
            'data' => ['doc_num' => $record->doc_num, 'urls' => $this->urls($record)],
        ]);
    }

    public function update(UpdateOpeningStockPricingRequest $request, string $openingStockPricing): JsonResponse
    {
        $record = $this->findInCurrentContext($request, $openingStockPricing);
        $result = $this->guardDomain(fn (): array => $this->service->update($record, $request->validated()));
        $record = $result['record'];

        return response()->json([
            'success' => true,
            'message' => __('inventory.opening_stock_pricings.messages.updated'),
            ...$this->saveResponse($request, $record, 'update'),
            'data' => [
                'old_doc_number' => $result['old_doc_number'],
                'old_doc_num' => $result['old_doc_num'],
                'doc_num' => $record->doc_num,
                'doc_number' => $record->doc_number,
                'urls' => $this->urls($record),
            ],
        ]);
    }

    public function destroy(Request $request, string $openingStockPricing): JsonResponse
    {
        $this->guardDomain(function () use ($request, $openingStockPricing): null {
            $this->service->delete($this->findInCurrentContext($request, $openingStockPricing));

            return null;
        });

        return response()->json(['success' => true, 'message' => __('inventory.opening_stock_pricings.messages.deleted')]);
    }

    public function restore(Request $request, string $openingStockPricing): JsonResponse
    {
        $this->guardDomain(fn (): OpeningStockPricing => $this->service->restore($this->findInCurrentContext($request, $openingStockPricing, true)));

        return response()->json(['success' => true, 'message' => __('inventory.opening_stock_pricings.messages.restored')]);
    }

    public function updateDocumentNumberSettings(UpdateOpeningStockPricingDocumentNumberSettingsRequest $request, FinanceDocumentNumberSettingsService $settings): JsonResponse
    {
        $result = $settings->update('inventory_opening_stock_pricings', $request->validated('prefix'), (int) $request->validated('padding'));

        return response()->json([
            'success' => true,
            'message' => __('inventory.opening_stock_pricings.document_number_settings.updated_successfully'),
            'data' => $result['new'],
        ]);
    }

    public function branches(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless($this->canUsePricing($request), 403);

        return response()->json($select2->pricingBranches($request));
    }

    public function branchHalls(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless($this->canUsePricing($request), 403);

        return response()->json($select2->pricingBranchHalls($request));
    }

    public function currencies(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless($this->canUsePricing($request), 403);

        return response()->json($select2->pricingCurrencies($request));
    }

    public function openingStocks(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless($this->canUsePricing($request), 403);

        return response()->json($select2->pricingOpeningStocks($request));
    }

    public function lines(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless($this->canUsePricing($request), 403);

        return response()->json($select2->pricingLines($request));
    }

    public function remainingLines(Request $request, InventorySelect2Service $select2): JsonResponse
    {
        abort_unless($this->canUsePricing($request), 403);

        return response()->json(['success' => true, 'data' => $select2->remainingPricingLines($request)]);
    }

    private function form(Request $request, string $mode, ?OpeningStockPricing $record = null, ?string $cloneSourceToken = null): View
    {
        $record?->loadMissing(['branch', 'branchHall', 'openingStock.branch', 'openingStock.branchHall', 'currency', 'lines.openingStockLine.product']);
        $isCreateLike = in_array($mode, ['create', 'clone'], true);
        $mainCurrency = $this->mainCurrency($request);

        return view('modules.inventory.opening-stock-pricings.form', [
            'mode' => $mode,
            'record' => $record,
            'action' => $isCreateLike ? route('admin.inventory.opening-stock-pricings.store') : route('admin.inventory.opening-stock-pricings.update', $record?->doc_num),
            'method' => $isCreateLike ? 'POST' : 'PUT',
            'canControlDocumentNumber' => (bool) auth()->user()?->can('inventory.opening_stock_pricings.document_number.control'),
            'breadcrumbs' => $this->breadcrumbs($mode, $record),
            'cloneSourceToken' => $cloneSourceToken,
            'dateValue' => $this->defaultDate($request, $record, $isCreateLike),
            'branchOption' => $this->branchOption($record),
            'hallOption' => $this->hallOption($record),
            'openingStockOption' => $this->openingStockOption($record),
            'currencyOption' => $record?->currency ? $this->currencyOption($record->currency) : ($mainCurrency ? $this->currencyOption($mainCurrency) : null),
            'mainCurrencyDocNum' => $mainCurrency?->doc_num,
            'exchangeRateValue' => $record?->exchange_rate ?? ($mainCurrency ? '1' : null),
            'lines' => $this->linesForForm($record, $mode),
            'metadata' => $this->metadata($record),
            'isLocked' => $record?->isLockedForEditing() ?? false,
        ]);
    }

    private function findInCurrentContext(Request $request, string $docNum, bool $withTrashed = false): OpeningStockPricing
    {
        $context = $this->operatingContext->snapshot($request);

        abort_unless($context['company_id'] && $context['financial_period_id'], 404);

        $query = $withTrashed ? OpeningStockPricing::withTrashed() : OpeningStockPricing::query();

        return $query
            ->where('doc_num', $docNum)
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->firstOrFail();
    }

    private function defaultDate(Request $request, ?OpeningStockPricing $record, bool $isCreateLike): string
    {
        $dates = app(DateFormatService::class);

        if ($record instanceof OpeningStockPricing && ! $isCreateLike) {
            return $record->document_date ? $dates->formatDate($record->document_date, '') : '';
        }

        $periodId = $this->operatingContext->snapshot($request)['financial_period_id'];
        $period = $periodId ? FinancialPeriod::query()->find($periodId) : null;
        $today = now()->toDateString();
        $default = $period && $period->from_date && $period->to_date && ($today < $period->from_date->toDateString() || $today > $period->to_date->toDateString())
            ? $period->from_date
            : now();

        return $dates->formatDate($default, '');
    }

    private function mainCurrency(Request $request): ?Currency
    {
        $companyId = $this->operatingContext->snapshot($request)['company_id'];

        return $companyId
            ? Currency::query()->active()->forCompany((int) $companyId)->where('is_main', true)->first()
            : null;
    }

    private function branchOption(?OpeningStockPricing $record): ?array
    {
        return $record?->branch instanceof Branch ? [
            'id' => (string) $record->branch->doc_num,
            'text' => trim(implode(' / ', array_filter([$record->branch->doc_num, $record->branch->name]))),
            'type' => $record->branch->type,
        ] : null;
    }

    private function hallOption(?OpeningStockPricing $record): ?array
    {
        return $record?->branchHall instanceof BranchHall ? [
            'id' => (string) $record->branchHall->public_uuid,
            'text' => (string) $record->branchHall->name,
        ] : null;
    }

    private function openingStockOption(?OpeningStockPricing $record): ?array
    {
        $date = $record?->openingStock?->document_date
            ? app(DateFormatService::class)->formatDate($record->openingStock->document_date, '')
            : null;

        return $record?->openingStock instanceof OpeningStock ? [
            'id' => (string) $record->openingStock->doc_num,
            'text' => trim(implode(' / ', array_filter([
                $record->openingStock->doc_num,
                $date,
                $record->openingStock->branch?->name,
                $record->openingStock->branchHall?->name,
            ]))),
        ] : null;
    }

    private function currencyOption(Currency $currency): array
    {
        return [
            'id' => (string) $currency->doc_num,
            'text' => trim(implode(' / ', array_filter([$currency->code, $currency->name]))),
            'is_main' => (bool) $currency->is_main,
        ];
    }

    private function linesForForm(?OpeningStockPricing $record, string $mode): array
    {
        $lines = old('lines');

        if (! is_array($lines)) {
            $lines = $record?->lines?->map(function (OpeningStockPricingLine $line) use ($mode): array {
                $snapshot = is_array($line->product_snapshot) ? $line->product_snapshot : [];
                $label = trim(implode(' / ', array_filter([
                    $snapshot['doc_num'] ?? null,
                    $snapshot['name'] ?? null,
                    $snapshot['barcode'] ?? null,
                    $snapshot['unit_label'] ?? null,
                    $this->formatNumber($line->quantity),
                ])));

                return [
                    'public_id' => $mode === 'clone' ? null : $line->public_id,
                    'opening_stock_line_public_id' => $line->openingStockLine?->public_id,
                    'product_label' => $label,
                    'imageUrl' => $snapshot['image_url'] ?? null,
                    'unit' => $snapshot['unit_label'] ?? null,
                    'quantity' => $this->formatNumber($line->quantity),
                    'unit_price' => $this->formatNumber($line->unit_price),
                    'line_total' => $this->formatNumber($line->line_total),
                    'notes' => $line->notes,
                    'product_data' => [
                        'imageUrl' => $snapshot['image_url'] ?? null,
                        'doc_num' => $snapshot['doc_num'] ?? null,
                        'name' => $snapshot['name'] ?? null,
                        'barcode' => $snapshot['barcode'] ?? null,
                        'unit' => $snapshot['unit_label'] ?? null,
                        'quantity' => $this->formatNumber($line->quantity),
                    ],
                ];
            })->values()->all() ?? [];
        }

        if ($lines === [] && $mode !== 'view') {
            return [['public_id' => null, 'opening_stock_line_public_id' => null, 'product_label' => null, 'unit' => null, 'quantity' => null, 'unit_price' => null, 'line_total' => null, 'notes' => null, 'product_data' => []]];
        }

        return array_values($lines);
    }

    private function formatNumber(mixed $value, int $precision = 4): string
    {
        return rtrim(rtrim(number_format((float) $value, $precision, '.', ''), '0'), '.') ?: '0';
    }

    private function breadcrumbs(string $mode, ?OpeningStockPricing $record): array
    {
        $extra = match ($mode) {
            'create' => [['label' => __('breadcrumb.create')]],
            'clone' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.inventory.opening-stock-pricings.show', $record->doc_num) : null],
                ['label' => __('common.actions.clone_record')],
            ],
            'edit' => [
                ['label' => (string) $record?->doc_num, 'url' => $record ? route('admin.inventory.opening-stock-pricings.show', $record->doc_num) : null],
                ['label' => __('breadcrumb.edit')],
            ],
            'view' => [['label' => (string) $record?->doc_num]],
            default => [],
        };

        return $this->breadcrumbs->forMenuRoute('admin.inventory.opening-stock-pricings.index', $extra);
    }

    private function urls(OpeningStockPricing $record): array
    {
        return [
            'show' => route('admin.inventory.opening-stock-pricings.show', $record->doc_num),
            'edit' => route('admin.inventory.opening-stock-pricings.edit', $record->doc_num),
            'clone' => route('admin.inventory.opening-stock-pricings.clone', $record->doc_num),
            'update' => route('admin.inventory.opening-stock-pricings.update', $record->doc_num),
            'destroy' => route('admin.inventory.opening-stock-pricings.destroy', $record->doc_num),
        ];
    }

    private function saveResponse(Request $request, OpeningStockPricing $record, string $operation): array
    {
        $action = $this->submitAction($request, $operation === 'store');
        $redirect = match ($action) {
            'save_view' => route('admin.inventory.opening-stock-pricings.show', $record->doc_num),
            'save_edit' => route('admin.inventory.opening-stock-pricings.edit', $record->doc_num),
            'save_back' => route('admin.inventory.opening-stock-pricings.index'),
            'save_clone' => route('admin.inventory.opening-stock-pricings.clone', $record->doc_num),
            default => $operation === 'store' ? route('admin.inventory.opening-stock-pricings.create') : null,
        };

        return array_filter(['submit_action' => $action, 'redirect' => $redirect]);
    }

    private function submitAction(Request $request, bool $creating = false): string
    {
        $action = $request->string('submit_action')->trim()->toString() ?: 'save';
        if ($action === 'save_new' || (! $creating && $action === 'save_edit')) {
            return 'save';
        }

        return in_array($action, ['save', 'save_view', 'save_edit', 'save_back', 'save_clone'], true) ? $action : 'save';
    }

    private function guardDomain(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['document' => $exception->getMessage()]);
        }
    }

    private function canUsePricing(Request $request): bool
    {
        return (bool) $request->user()?->can('inventory.opening_stock_pricings.view')
            || (bool) $request->user()?->can('inventory.opening_stock_pricings.create')
            || (bool) $request->user()?->can('inventory.opening_stock_pricings.edit');
    }

    /**
     * @return array<string, string|null>
     */
    private function metadata(?OpeningStockPricing $record): array
    {
        if (! $record instanceof OpeningStockPricing) {
            return [
                'created_by' => null,
                'created_at' => null,
                'updated_by' => null,
                'updated_at' => null,
                'deleted_by' => null,
                'deleted_at' => null,
                'restored_by' => null,
                'restored_at' => null,
            ];
        }

        $users = User::query()
            ->whereIn('id', array_filter([$record->created_by, $record->updated_by, $record->deleted_by, $record->restored_by]))
            ->get(['id', 'name', 'doc_num'])
            ->keyBy('id');
        $settings = app(SettingService::class);

        return [
            'created_by' => $this->auditUserLabel($users->get($record->created_by)),
            'created_at' => $settings->formatDateTime($record->created_at, ''),
            'updated_by' => $this->auditUserLabel($users->get($record->updated_by)),
            'updated_at' => $settings->formatDateTime($record->updated_at, ''),
            'deleted_by' => $this->auditUserLabel($users->get($record->deleted_by)),
            'deleted_at' => $settings->formatDateTime($record->deleted_at, ''),
            'restored_by' => $this->auditUserLabel($users->get($record->restored_by)),
            'restored_at' => $settings->formatDateTime($record->restored_at, ''),
        ];
    }

    private function auditUserLabel(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        return trim(implode(' / ', array_filter([$user->name, $user->doc_num])));
    }
}
