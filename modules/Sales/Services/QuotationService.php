<?php

namespace Modules\Sales\Services;

use App\Models\User;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Currency;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\CrudAuditService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\QuotationAttachment;
use Modules\Sales\Models\QuotationRevision;

class QuotationService
{
    public function __construct(
        private readonly DocumentNumberService $documents,
        private readonly CrudAuditService $audit,
        private readonly OperatingCompanyContextService $companies,
        private readonly QuotationCalculationService $calculator,
        private readonly FilePickerService $filePicker,
        private readonly NumericFormatService $numbers,
        private readonly SalesUnitConversionService $unitConversions,
    ) {}

    public function create(array $data, ?Request $request = null): array
    {
        return DB::transaction(function () use ($data, $request): array {
            $companyId = $this->companies->requireCompanyId($request);
            $record = Quotation::query()->create([
                ...$this->quotationValues($data, $companyId),
                ...$this->document($data, $companyId),
                'status' => Quotation::StatusDraft,
                'created_by' => auth()->id(),
            ]);

            $revision = $this->createRevisionFromData($record, $data, 1, QuotationRevision::StatusDraft);
            $record->forceFill(['current_revision_id' => $revision->getKey()])->save();
            $this->attachArchiveFiles($record->refresh(), $data['attachment_file_doc_nums'] ?? [], $request);
            $this->audit->clearCreationUpdateAudit($record);

            return ['record' => $record->refresh()->load($this->defaultRelations())];
        });
    }

    public function update(Quotation $record, array $data): array
    {
        return DB::transaction(function () use ($record, $data): array {
            $record->loadMissing('currentRevision');
            $this->assertEditable($record);

            $oldDocNumber = $record->doc_number === null ? null : (int) $record->doc_number;
            $oldDocNum = $record->doc_num;
            $values = $this->quotationValues($data, (int) $record->company_id);

            if (array_key_exists('doc_number', $data) && $data['doc_number']) {
                $values = [...$values, ...$this->document($data, (int) $record->company_id)];
            }

            $this->audit->saveUpdate($record, $values);
            $revision = $record->currentRevision;

            if (! $revision instanceof QuotationRevision) {
                $revision = $this->createRevisionFromData($record->refresh(), $data, 1, QuotationRevision::StatusDraft);
                $record->forceFill(['current_revision_id' => $revision->getKey()])->save();
            } else {
                $this->updateRevisionFromData($revision, $data);
            }

            return [
                'record' => $record->refresh()->load($this->defaultRelations()),
                'changed' => true,
                'old_doc_number' => $oldDocNumber,
                'old_doc_num' => $oldDocNum,
            ];
        });
    }

    public function createNewRevision(Quotation $record, ?string $changeReason = null): QuotationRevision
    {
        return DB::transaction(function () use ($record, $changeReason): QuotationRevision {
            $record->loadMissing(['currentRevision.lines', 'currentRevision.paymentMilestones', 'currentRevision.executionScheduleLines']);
            $source = $record->currentRevision;

            if (! $source instanceof QuotationRevision) {
                throw new DomainException(__('quotations.messages.no_current_revision'));
            }

            if ($record->status === Quotation::StatusCancelled) {
                throw new DomainException(__('quotations.messages.cancelled_not_editable'));
            }

            $nextNumber = ((int) $record->revisions()->max('revision_number')) + 1;

            if (! in_array($source->status, [QuotationRevision::StatusAccepted, QuotationRevision::StatusCancelled], true)) {
                $source->forceFill(['status' => QuotationRevision::StatusSuperseded])->save();
            }

            $revision = $record->revisions()->create([
                ...$source->only([
                    'customer_feedback',
                    'subtotal',
                    'discount_type',
                    'discount_value',
                    'discount_amount',
                    'tax_amount',
                    'total',
                    'notes_snapshot',
                    'terms_snapshot',
                    'payment_terms_snapshot',
                    'execution_terms_snapshot',
                    'warranty_terms_snapshot',
                    'technical_notes_snapshot',
                    'delivery_terms_snapshot',
                ]),
                'revision_number' => $nextNumber,
                'revision_code' => $this->revisionCode($record, $nextNumber),
                'revision_date' => now()->toDateString(),
                'status' => QuotationRevision::StatusDraft,
                'change_reason' => $changeReason,
                'created_by' => auth()->id(),
            ]);

            foreach ($source->lines as $line) {
                $revision->lines()->create($line->only([
                    'line_number',
                    'product_id',
                    'item_id',
                    'description',
                    'unit_id',
                    'quantity',
                    'conversion_factor',
                    'base_quantity',
                    'unit_price',
                    'discount_type',
                    'discount_value',
                    'discount_amount',
                    'tax_rate',
                    'tax_amount',
                    'line_total',
                    'requested_date',
                    'notes',
                    'product_name_snapshot',
                    'unit_name_snapshot',
                    'specs_snapshot',
                    'specifications',
                    'warehouse_notes',
                    'production_notes',
                ]));
            }

            foreach ($source->paymentMilestones as $milestone) {
                $revision->paymentMilestones()->create($milestone->only([
                    'line_number',
                    'title',
                    'description',
                    'percentage',
                    'amount',
                    'due_type',
                    'due_date',
                    'notes',
                ]));
            }

            foreach ($source->executionScheduleLines as $line) {
                $revision->executionScheduleLines()->create($line->only([
                    'line_number',
                    'phase_name',
                    'description',
                    'start_date',
                    'end_date',
                    'duration_days',
                    'responsibility',
                    'notes',
                ]));
            }

            $record->forceFill([
                'current_revision_id' => $revision->getKey(),
                'status' => Quotation::StatusDraft,
                'updated_by' => auth()->id(),
            ])->save();

            return $revision->refresh()->load(['lines', 'paymentMilestones', 'executionScheduleLines']);
        });
    }

    public function markSent(Quotation $record): Quotation
    {
        return $this->transition($record, Quotation::StatusSent, QuotationRevision::StatusSent, [Quotation::StatusDraft], [QuotationRevision::StatusDraft]);
    }

    public function accept(Quotation $record): Quotation
    {
        return $this->transition($record, Quotation::StatusAccepted, QuotationRevision::StatusAccepted, [Quotation::StatusSent, Quotation::StatusUnderReview], [QuotationRevision::StatusSent]);
    }

    public function reject(Quotation $record): Quotation
    {
        return $this->transition($record, Quotation::StatusRejected, QuotationRevision::StatusRejected, [Quotation::StatusSent, Quotation::StatusUnderReview], [QuotationRevision::StatusSent]);
    }

    public function cancel(Quotation $record): Quotation
    {
        return DB::transaction(function () use ($record): Quotation {
            $record->loadMissing('currentRevision');

            if (in_array($record->status, [Quotation::StatusCancelled, Quotation::StatusConverted], true)) {
                throw new DomainException(__('quotations.messages.transition_not_allowed'));
            }

            $record->currentRevision?->forceFill(['status' => QuotationRevision::StatusCancelled])->save();
            $this->audit->saveUpdate($record, ['status' => Quotation::StatusCancelled]);

            return $record->refresh()->load($this->defaultRelations());
        });
    }

    public function delete(Quotation $record): void
    {
        DB::transaction(function () use ($record): void {
            $this->audit->softDelete($record);
        });
    }

    public function bulkDelete(array $docNums): int
    {
        return DB::transaction(function () use ($docNums): int {
            $deleted = 0;

            foreach (Quotation::query()->forCompany($this->companies->requireCompanyId())->whereIn('doc_num', $docNums)->get() as $record) {
                $this->delete($record);
                $deleted++;
            }

            return $deleted;
        });
    }

    public function restore(Quotation $record): Quotation
    {
        return DB::transaction(function () use ($record): Quotation {
            if (Quotation::query()->forCompany((int) $record->company_id)->where('doc_num', $record->doc_num)->whereKeyNot($record->getKey())->exists()) {
                throw new DomainException(__('quotations.messages.restore_conflict'));
            }

            $this->audit->restore($record, auth()->id());

            return $record->refresh()->load($this->defaultRelations());
        });
    }

    public function destroyAttachment(QuotationAttachment $attachment): void
    {
        $attachment->delete();
    }

    /**
     * @return list<string>
     */
    public function defaultRelations(): array
    {
        return [
            'customer',
            'branch',
            'currency',
            'salesPerson',
            'currentRevision.lines.product',
            'currentRevision.lines.unit',
            'currentRevision.paymentMilestones',
            'currentRevision.executionScheduleLines',
            'revisions.createdBy',
            'attachments.archiveFile',
            'attachments.uploadedBy',
            'salesOrders',
        ];
    }

    private function transition(Quotation $record, string $newStatus, string $newRevisionStatus, array $allowedStatuses, array $allowedRevisionStatuses): Quotation
    {
        return DB::transaction(function () use ($record, $newStatus, $newRevisionStatus, $allowedStatuses, $allowedRevisionStatuses): Quotation {
            $record->loadMissing('currentRevision');
            $revision = $record->currentRevision;

            if (! in_array($record->status, $allowedStatuses, true) || ! $revision instanceof QuotationRevision || ! in_array($revision->status, $allowedRevisionStatuses, true)) {
                throw new DomainException(__('quotations.messages.transition_not_allowed'));
            }

            $revision->forceFill(['status' => $newRevisionStatus])->save();
            $this->audit->saveUpdate($record, ['status' => $newStatus]);

            return $record->refresh()->load($this->defaultRelations());
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function quotationValues(array $data, int $companyId): array
    {
        return [
            'company_id' => $companyId,
            'branch_id' => $data['branch_id'],
            'customer_id' => $this->customerId($companyId, $data['customer_doc_num'] ?? null),
            'customer_reference' => $data['customer_reference'] ?? null,
            'quotation_type' => $data['quotation_type'] ?? Quotation::TypeStandard,
            'project_name' => $data['project_name'] ?? null,
            'subject' => $data['subject'] ?? null,
            'quotation_date' => $data['quotation_date'],
            'valid_until' => $data['valid_until'] ?? null,
            'currency_id' => $this->currencyId($companyId, $data['currency_doc_num'] ?? null),
            'exchange_rate' => $this->numbers->normalizeToScale($data['exchange_rate'] ?? 1, 6) ?? '1.000000',
            'sales_person_id' => $this->salesPersonId($data['sales_person_doc_num'] ?? null),
            'notes' => $data['notes'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
        ];
    }

    /**
     * @return array{doc_number: int, doc_num: string}
     */
    private function document(array $data, int $companyId): array
    {
        return array_key_exists('doc_number', $data) && $data['doc_number']
            ? ['doc_number' => (int) $data['doc_number'], 'doc_num' => $this->documents->format('quotations', (int) $data['doc_number'])]
            : $this->documents->nextForCompany('quotations', Quotation::class, $companyId);
    }

    private function createRevisionFromData(Quotation $record, array $data, int $revisionNumber, string $status): QuotationRevision
    {
        $revision = $record->revisions()->create([
            ...$this->revisionValues($record, $data, $revisionNumber, $status),
            'created_by' => auth()->id(),
        ]);

        $this->syncRevisionChildren($record, $revision, $data);

        return $revision->refresh()->load(['lines', 'paymentMilestones', 'executionScheduleLines']);
    }

    private function updateRevisionFromData(QuotationRevision $revision, array $data): QuotationRevision
    {
        $record = $revision->quotation()->firstOrFail();
        $revision->forceFill($this->revisionValues($record, $data, (int) $revision->revision_number, (string) $revision->status))->save();
        $this->syncRevisionChildren($record, $revision->refresh(), $data);

        return $revision->refresh()->load(['lines', 'paymentMilestones', 'executionScheduleLines']);
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionValues(Quotation $record, array $data, int $revisionNumber, string $status): array
    {
        $calculation = $this->calculator->calculate($data['lines'] ?? [], $data['discount_type'] ?? null, $data['discount_value'] ?? 0);

        return [
            'revision_number' => $revisionNumber,
            'revision_code' => $this->revisionCode($record, $revisionNumber),
            'revision_date' => $data['revision_date'] ?? $data['quotation_date'],
            'status' => $status,
            'change_reason' => $data['change_reason'] ?? null,
            'customer_feedback' => $data['customer_feedback'] ?? null,
            ...$calculation['revision'],
            'notes_snapshot' => $this->sanitizeRichText($data['notes'] ?? null),
            'terms_snapshot' => $this->sanitizeRichText($data['terms'] ?? null),
            'payment_terms_snapshot' => $this->sanitizeRichText($data['payment_terms'] ?? null),
            'execution_terms_snapshot' => $this->sanitizeRichText($data['execution_terms'] ?? null),
            'warranty_terms_snapshot' => $this->sanitizeRichText($data['warranty_terms'] ?? null),
            'technical_notes_snapshot' => $this->sanitizeRichText($data['technical_notes'] ?? null),
            'delivery_terms_snapshot' => $this->sanitizeRichText($data['delivery_terms'] ?? null),
        ];
    }

    private function syncRevisionChildren(Quotation $record, QuotationRevision $revision, array $data): void
    {
        $calculation = $this->calculator->calculate($data['lines'] ?? [], $data['discount_type'] ?? null, $data['discount_value'] ?? 0);

        $revision->lines()->delete();
        foreach ($calculation['lines'] as $index => $line) {
            $product = $this->productByDocNum((int) $record->company_id, $line['product_doc_num'] ?? null);
            $unit = $this->unitByDocNum((int) $record->company_id, $line['unit_doc_num'] ?? null) ?: $product?->unit;

            if (! $product instanceof Product || ! $product->isSalesEligible()) {
                throw new DomainException(__('quotations.messages.product_sales_ineligible'));
            }

            $unitSnapshot = $this->unitConversions->snapshot($product, $unit?->getKey(), $line['quantity']);

            $revision->lines()->create([
                'line_number' => $index + 1,
                'product_id' => $product?->getKey(),
                'item_id' => $product?->getKey(),
                'description' => $line['description'] ?? null,
                'unit_id' => $unitSnapshot['unit_id'],
                'quantity' => $line['quantity'],
                'conversion_factor' => $unitSnapshot['conversion_factor'],
                'base_quantity' => $unitSnapshot['base_quantity'],
                'unit_price' => $line['unit_price'],
                'discount_type' => $line['discount_type'] ?? null,
                'discount_value' => $line['discount_value'],
                'discount_amount' => $line['discount_amount'],
                'tax_rate' => $line['tax_rate'],
                'tax_amount' => $line['tax_amount'],
                'line_total' => $line['line_total'],
                'requested_date' => $line['requested_date'] ?? null,
                'notes' => $line['notes'] ?? null,
                'product_name_snapshot' => $product?->name ?: ($line['description'] ?? null),
                'unit_name_snapshot' => $unit?->name,
                'specs_snapshot' => $product ? $this->productSpecsSnapshot($product) : null,
                'specifications' => collect($line['specifications'] ?? [])->filter(fn (mixed $value): bool => filled($value))->all() ?: null,
                'warehouse_notes' => $line['warehouse_notes'] ?? null,
                'production_notes' => $line['production_notes'] ?? null,
            ]);
        }

        $revision->paymentMilestones()->delete();
        foreach (($data['payment_milestones'] ?? []) as $index => $milestone) {
            $revision->paymentMilestones()->create([
                'line_number' => $index + 1,
                'title' => $milestone['title'],
                'description' => $milestone['description'] ?? null,
                'percentage' => $milestone['percentage'] ?? null,
                'amount' => $milestone['amount'] ?? null,
                'due_type' => $milestone['due_type'] ?? null,
                'due_date' => $milestone['due_date'] ?? null,
                'notes' => $milestone['notes'] ?? null,
            ]);
        }

        $revision->executionScheduleLines()->delete();
        foreach (($data['execution_schedule_lines'] ?? []) as $index => $line) {
            $revision->executionScheduleLines()->create([
                'line_number' => $index + 1,
                'phase_name' => $line['phase_name'],
                'description' => $line['description'] ?? null,
                'start_date' => $line['start_date'] ?? null,
                'end_date' => $line['end_date'] ?? null,
                'duration_days' => $line['duration_days'] ?? null,
                'responsibility' => $line['responsibility'] ?? null,
                'notes' => $line['notes'] ?? null,
            ]);
        }
    }

    private function revisionCode(Quotation $record, int $revisionNumber): string
    {
        return sprintf('%s-R%02d', $record->doc_num, $revisionNumber);
    }

    private function assertEditable(Quotation $record): void
    {
        if ($record->status === Quotation::StatusCancelled) {
            throw new DomainException(__('quotations.messages.cancelled_not_editable'));
        }

        if (! $record->canEditCurrentRevision()) {
            throw new DomainException(__('quotations.messages.revision_not_draft'));
        }
    }

    private function customerId(int $companyId, ?string $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        return $docNum === '' ? null : Customer::query()->forCompany($companyId)->where('doc_num', $docNum)->whereNull('deleted_at')->value('id');
    }

    private function currencyId(int $companyId, ?string $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        return $docNum === '' ? null : Currency::query()->forCompany($companyId)->where('doc_num', $docNum)->whereNull('deleted_at')->value('id');
    }

    private function salesPersonId(?string $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        return $docNum === '' ? null : User::query()->where('doc_num', $docNum)->where('status', 'active')->whereNull('deleted_at')->value('id');
    }

    private function productByDocNum(int $companyId, ?string $docNum): ?Product
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return Product::query()
            ->active()
            ->salesEligible()
            ->forCompany($companyId)
            ->where('products.doc_num', $docNum)
            ->leftJoin('item_units', 'item_units.id', '=', 'products.item_unit_id')
            ->leftJoin('item_categories', 'item_categories.id', '=', 'products.item_category_id')
            ->leftJoin('item_groups', 'item_groups.id', '=', 'products.item_group_id')
            ->leftJoin('item_models', 'item_models.id', '=', 'products.item_model_id')
            ->leftJoin('item_colors', 'item_colors.id', '=', 'products.item_color_id')
            ->leftJoin('item_sizes', 'item_sizes.id', '=', 'products.item_size_id')
            ->select([
                'products.*',
                'item_units.doc_num as unit_doc_num',
                'item_units.name as unit_name',
                'item_categories.name as category_name',
                'item_groups.name as group_name',
                'item_models.name as model_name',
                'item_colors.name as color_name',
                'item_sizes.name as size_name',
            ])
            ->first();
    }

    private function unitByDocNum(int $companyId, ?string $docNum): ?ItemUnit
    {
        $docNum = trim((string) $docNum);

        return $docNum === '' ? null : ItemUnit::query()->active()->forCompany($companyId)->where('doc_num', $docNum)->first();
    }

    private function productSpecsSnapshot(Product $product): ?string
    {
        $parts = array_filter([
            $product->barcode ? __('products.attributes.barcode').': '.$product->barcode : null,
            $product->category_name ? __('products.attributes.category').': '.$product->category_name : null,
            $product->group_name ? __('products.attributes.group').': '.$product->group_name : null,
            $product->model_name ? __('products.attributes.model').': '.$product->model_name : null,
            $product->color_name ? __('products.attributes.color').': '.$product->color_name : null,
            $product->size_name ? __('products.attributes.size').': '.$product->size_name : null,
        ]);

        return $parts === [] ? null : implode("\n", $parts);
    }

    /**
     * @param  list<string>  $fileDocNums
     */
    private function attachArchiveFiles(Quotation $record, array $fileDocNums, ?Request $request): void
    {
        if ($fileDocNums === []) {
            return;
        }

        $companyId = $this->companies->requireCompanyId($request);
        $existingArchiveFileIds = $record->attachments()
            ->whereNotNull('archive_file_id')
            ->pluck('archive_file_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($fileDocNums as $fileDocNum) {
            $file = $this->filePicker->selectableFileByPublicId($fileDocNum, $companyId, FilePickerService::AcceptDocument);

            if (! $file instanceof ArchiveFile) {
                throw ValidationException::withMessages([
                    'attachment_file_doc_nums' => __('quotations.messages.selected_file_unavailable'),
                ]);
            }

            if (in_array((int) $file->getKey(), $existingArchiveFileIds, true)) {
                continue;
            }

            $record->attachments()->create([
                'archive_file_id' => $file->getKey(),
                'disk' => $file->disk,
                'path' => $file->path,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size' => (int) $file->size_bytes,
                'uploaded_by' => auth()->id(),
            ]);

            $existingArchiveFileIds[] = (int) $file->getKey();
        }
    }

    private function sanitizeRichText(mixed $value): ?string
    {
        $html = trim((string) $value);

        if ($html === '') {
            return null;
        }

        $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('/\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/i', '', $html) ?? '';
        $html = preg_replace('/\s(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2/i', ' $1="#"', $html) ?? '';

        $hasText = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '';
        $hasImage = preg_match('/<img\b/i', $html) === 1;

        if (! $hasText && ! $hasImage) {
            return null;
        }

        $allowedTags = '<p><br><b><strong><i><em><u><s><ul><ol><li><blockquote><pre><code><a><img><span><div><h1><h2><h3><h4><h5><h6><table><thead><tbody><tr><th><td>';

        return strip_tags($html, $allowedTags) ?: null;
    }
}
