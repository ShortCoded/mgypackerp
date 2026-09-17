<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Models\OpeningBalance;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockPricing;
use Throwable;

class OpenDocumentsService
{
    public const OpeningBalances = 'opening_balances';

    public const OpeningStocks = 'opening_stocks';

    public const OpeningStockPricings = 'opening_stock_pricings';

    public function __construct(
        private readonly CrudAuditService $audit,
        private readonly ActivityLogger $activityLogger,
        private readonly OperatingContextService $operatingContext,
    ) {}

    /**
     * @return array<string, string>
     */
    public function documentTypes(): array
    {
        return collect($this->handlers())
            ->mapWithKeys(fn (array $handler, string $key): array => [$key => __($handler['label'])])
            ->all();
    }

    /**
     * @return list<string>
     */
    public function supportedTypeKeys(): array
    {
        return array_keys($this->handlers());
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     messages: list<string>,
     *     summary: array<string, int|string>
     * }
     */
    public function reopen(string $documentType, int $fromNumber, int $toNumber, Request $request): array
    {
        $handler = $this->handler($documentType);
        $context = $this->currentContext($request);

        return DB::transaction(function () use ($handler, $context, $fromNumber, $toNumber, $request): array {
            $records = $this->recordsQuery($handler, $context, $fromNumber, $toNumber)
                ->lockForUpdate()
                ->orderBy('doc_number')
                ->get();

            $summary = [
                'document_type' => $handler['type'],
                'from_number' => $fromNumber,
                'to_number' => $toNumber,
                'total_found' => $records->count(),
                'opened' => 0,
                'skipped_approved' => 0,
                'skipped_already_open' => 0,
                'skipped_deleted' => 0,
                'not_found' => max(0, ($toNumber - $fromNumber + 1) - $records->count()),
            ];

            foreach ($records as $record) {
                if ($record->trashed()) {
                    $summary['skipped_deleted']++;

                    continue;
                }

                if ($this->isApproved($record, $handler)) {
                    $summary['skipped_approved']++;

                    continue;
                }

                if (! $this->canReopen($record, $handler)) {
                    $summary['skipped_already_open']++;

                    continue;
                }

                $oldStatus = (string) $record->getAttribute('status');
                $oldIsClosed = (bool) $record->getAttribute('is_closed');

                $this->audit->saveUpdate($record, [
                    'is_closed' => false,
                    'status' => $handler['open_status'],
                ], $request->user()?->getKey());

                $summary['opened']++;
                $this->logOpened($request, $record->refresh(), $handler, $context, $oldStatus, $oldIsClosed);
            }

            $messages = $this->resultMessages($summary);

            return [
                'success' => $summary['opened'] > 0,
                ...($summary['opened'] === 0 ? ['type' => 'no_changes'] : []),
                'message' => $messages[0] ?? __('open_documents.messages.none_reopenable'),
                'messages' => $messages,
                'summary' => $summary,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $handler
     * @return Builder<Model>
     */
    private function recordsQuery(array $handler, array $context, int $fromNumber, int $toNumber): Builder
    {
        /** @var class-string<Model> $model */
        $model = $handler['model'];

        return $model::withTrashed()
            ->where('company_id', $context['company_id'])
            ->where('financial_period_id', $context['financial_period_id'])
            ->whereBetween('doc_number', [$fromNumber, $toNumber]);
    }

    /**
     * @param  array<string, mixed>  $handler
     */
    private function isApproved(Model $record, array $handler): bool
    {
        if (array_key_exists('approved', $record->getAttributes()) && (bool) $record->getAttribute('approved')) {
            return true;
        }

        $approvedStatus = $handler['approved_status'] ?? null;

        return is_string($approvedStatus) && $approvedStatus !== '' && (string) $record->getAttribute('status') === $approvedStatus;
    }

    /**
     * @param  array<string, mixed>  $handler
     */
    private function canReopen(Model $record, array $handler): bool
    {
        if (! (bool) $record->getAttribute('is_closed')) {
            return false;
        }

        if ($this->hasJournalEntry($record)) {
            return false;
        }

        $status = (string) $record->getAttribute('status');

        if (in_array($status, $handler['blocked_statuses'] ?? [], true)) {
            return false;
        }

        $closedStatus = $handler['closed_status'] ?? null;

        return ! is_string($closedStatus) || $closedStatus === '' || $status === $closedStatus;
    }

    private function hasJournalEntry(Model $record): bool
    {
        return array_key_exists('journal_entry_id', $record->getAttributes())
            && $record->getAttribute('journal_entry_id') !== null;
    }

    /**
     * @param  array<string, int|string>  $summary
     * @return list<string>
     */
    private function resultMessages(array $summary): array
    {
        $messages = [];

        if ((int) $summary['opened'] > 0) {
            $messages[] = __('open_documents.messages.opened', ['count' => $summary['opened']]);
        } else {
            $messages[] = __('open_documents.messages.none_reopenable');
        }

        if ((int) $summary['skipped_approved'] > 0) {
            $messages[] = __('open_documents.messages.skipped_approved', ['count' => $summary['skipped_approved']]);
        }

        if ((int) $summary['skipped_already_open'] > 0) {
            $messages[] = __('open_documents.messages.skipped_already_open', ['count' => $summary['skipped_already_open']]);
        }

        if ((int) $summary['skipped_deleted'] > 0) {
            $messages[] = __('open_documents.messages.skipped_deleted', ['count' => $summary['skipped_deleted']]);
        }

        if ((int) $summary['not_found'] > 0) {
            $messages[] = __('open_documents.messages.not_found', ['count' => $summary['not_found']]);
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $handler
     * @param  array<string, int|string|null>  $context
     */
    private function logOpened(Request $request, Model $record, array $handler, array $context, string $oldStatus, bool $oldIsClosed): void
    {
        try {
            $this->activityLogger->log($request, 'core', 'tools.open_documents.open', 'success', [
                'properties_only' => true,
                'properties' => ActivityLogProperties::statusChanged(
                    'tools.open_documents',
                    __($handler['label']),
                    $record->getAttribute('doc_num'),
                    $oldStatus,
                    $handler['open_status'],
                    [
                        'document_type' => $handler['type'],
                        'document_number' => $record->getAttribute('doc_number'),
                        'old_is_closed' => $oldIsClosed,
                        'new_is_closed' => false,
                        'company_doc_num' => $context['company_doc_num'],
                        'financial_period_doc_num' => $context['financial_period_doc_num'],
                    ],
                ),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array{company_id: int, financial_period_id: int, company_doc_num: string|null, financial_period_doc_num: string|null}
     */
    private function currentContext(Request $request): array
    {
        $this->operatingContext->current($request);
        $context = $this->operatingContext->snapshot($request);

        if (! $context['company_id'] || ! $context['financial_period_id']) {
            throw new \DomainException(__('operating_context.messages.required'));
        }

        return [
            'company_id' => (int) $context['company_id'],
            'financial_period_id' => (int) $context['financial_period_id'],
            'company_doc_num' => $context['company_doc_num'],
            'financial_period_doc_num' => $context['financial_period_doc_num'],
        ];
    }

    /**
     * @return array{
     *     type: string,
     *     label: string,
     *     model: class-string<Model>,
     *     open_status: string,
     *     closed_status?: string|null,
     *     approved_status?: string|null,
     *     blocked_statuses?: list<string>
     * }
     */
    private function handler(string $documentType): array
    {
        $handlers = $this->handlers();

        if (! array_key_exists($documentType, $handlers)) {
            throw new \DomainException(__('open_documents.validation.invalid_document_type'));
        }

        return $handlers[$documentType];
    }

    /**
     * @return array<string, array{
     *     type: string,
     *     label: string,
     *     model: class-string<Model>,
     *     open_status: string,
     *     closed_status?: string|null,
     *     approved_status?: string|null,
     *     blocked_statuses?: list<string>
     * }>
     */
    private function handlers(): array
    {
        return [
            self::OpeningBalances => [
                'type' => self::OpeningBalances,
                'label' => 'open_documents.documents.opening_balances',
                'model' => OpeningBalance::class,
                'open_status' => OpeningBalance::StatusDraft,
                'closed_status' => null,
                'approved_status' => OpeningBalance::StatusApproved,
                'blocked_statuses' => [
                    OpeningBalance::StatusCancelled,
                    OpeningBalance::StatusReversed,
                ],
            ],
            self::OpeningStocks => [
                'type' => self::OpeningStocks,
                'label' => 'open_documents.documents.opening_stocks',
                'model' => OpeningStock::class,
                'open_status' => OpeningStock::StatusDraft,
                'closed_status' => OpeningStock::StatusClosed,
                'approved_status' => OpeningStock::StatusApproved,
                'blocked_statuses' => [],
            ],
            self::OpeningStockPricings => [
                'type' => self::OpeningStockPricings,
                'label' => 'open_documents.documents.opening_stock_pricings',
                'model' => OpeningStockPricing::class,
                'open_status' => OpeningStockPricing::StatusDraft,
                'closed_status' => OpeningStockPricing::StatusClosed,
                'approved_status' => null,
                'blocked_statuses' => [],
            ],
        ];
    }
}
