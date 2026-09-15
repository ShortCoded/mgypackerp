<?php

namespace Modules\Core\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Modules\Core\Models\ChatConversation;
use Modules\Core\Models\ChatMessage;
use Modules\Core\Services\ChatConversationReportService;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class ChatConversationReportExport implements WithMultipleSheets
{
    /** @param array<string, mixed> $filters */
    public function __construct(private readonly array $filters) {}

    /** @return list<object> */
    public function sheets(): array
    {
        return [
            new ChatConversationSummarySheet($this->filters),
            new ChatMessageTranscriptSheet($this->filters),
        ];
    }
}

abstract class ChatReportSheet extends DefaultValueBinder implements FromQuery, WithColumnWidths, WithCustomValueBinder, WithEvents, WithHeadings, WithMapping, WithTitle
{
    protected readonly ChatConversationReportService $report;

    /** @param array<string, mixed> $filters */
    public function __construct(protected readonly array $filters)
    {
        $this->report = app(ChatConversationReportService::class);
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    /** @return array<string, float|int> */
    public function columnWidths(): array
    {
        return [
            'A' => 38,
            'B' => 32,
            'C' => 38,
            'D' => 28,
            'E' => 55,
            'F' => 55,
            'G' => 38,
            'H' => 38,
            'I' => 21,
            'J' => 32,
            'K' => 18,
            'L' => 21,
        ];
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $event->sheet->getDelegate()->freezePane('A2');
                $event->sheet->getDelegate()->getStyle('1:1')->getFont()->setBold(true);
                $event->sheet->getDelegate()->getStyle('1:1')->getFill()
                    ->setFillType('solid')
                    ->getStartColor()->setARGB('FFD9EAF7');
                $event->sheet->getDelegate()->getStyle($event->sheet->calculateWorksheetDimension())
                    ->getAlignment()->setVertical('top')->setWrapText(true);
            },
        ];
    }
}

class ChatConversationSummarySheet extends ChatReportSheet
{
    /** @return Builder<ChatConversation> */
    public function query(): Builder
    {
        return $this->report->conversationQuery($this->filters)
            ->orderByRaw('last_message_at IS NULL')
            ->latest('last_message_at')
            ->latest('id');
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            __('chat.report.columns.conversation_id'),
            __('chat.report.columns.title'),
            __('chat.report.columns.type'),
            __('chat.report.columns.participants'),
            __('chat.report.columns.created_by'),
            __('chat.report.columns.created_at'),
            __('chat.report.columns.last_message_at'),
            __('chat.report.columns.message_count'),
            __('chat.report.columns.attachment_count'),
            __('chat.report.columns.status'),
        ];
    }

    /** @return list<mixed> */
    public function map($row): array
    {
        /** @var ChatConversation $row */
        return [
            $row->public_uuid,
            $this->report->title($row),
            __('chat.report.types.'.$row->type),
            $row->allParticipants->map(function ($participant): string {
                $state = $participant->pivot?->deleted_at ? __('chat.report.former_participant') : __('chat.report.current_participant');

                return "{$participant->name} ({$participant->doc_num}) — {$state}";
            })->implode("\n"),
            $row->creator ? "{$row->creator->name} ({$row->creator->doc_num})" : null,
            $row->created_at?->format('Y-m-d H:i:s'),
            $row->last_message_at?->format('Y-m-d H:i:s'),
            (int) $row->messages_count,
            (int) $row->attachments_count,
            $row->trashed() ? __('chat.report.deleted') : __('chat.report.active'),
        ];
    }

    public function title(): string
    {
        return mb_substr(__('chat.report.sheets.conversations'), 0, 31);
    }
}

class ChatMessageTranscriptSheet extends ChatReportSheet
{
    /** @return Builder<ChatMessage> */
    public function query(): Builder
    {
        return $this->report->messageQuery($this->filters)
            ->oldest('conversation_id')
            ->oldest('sent_at')
            ->oldest('id');
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            __('chat.report.columns.conversation_id'),
            __('chat.report.columns.conversation'),
            __('chat.report.columns.message_id'),
            __('chat.report.columns.sender'),
            __('chat.report.columns.message'),
            __('chat.report.columns.attachments'),
            __('chat.report.columns.reply_to'),
            __('chat.report.columns.forwarded_from'),
            __('chat.report.columns.sent_at'),
            __('chat.report.columns.read_by'),
            __('chat.report.columns.message_status'),
            __('chat.report.columns.deleted_at'),
        ];
    }

    /** @return list<mixed> */
    public function map($row): array
    {
        /** @var ChatMessage $row */
        $attachments = $row->attachments->map(function ($attachment): string {
            return $attachment->original_name.' — '.route('admin.chat.reports.attachments.show', $attachment);
        })->implode("\n");

        return [
            $row->conversation?->public_uuid,
            $row->conversation ? $this->report->title($row->conversation) : null,
            $row->public_uuid,
            $row->sender ? "{$row->sender->name} ({$row->sender->doc_num})" : null,
            $row->body,
            $attachments,
            $row->replyToMessage?->public_uuid,
            $row->forwardedFromMessage?->public_uuid ?: $row->forwardedFromUser?->name,
            $row->sent_at?->format('Y-m-d H:i:s'),
            implode(', ', $this->report->readBy($row)),
            $row->trashed() ? __('chat.report.deleted') : __('chat.report.active'),
            $row->deleted_at?->format('Y-m-d H:i:s'),
        ];
    }

    public function title(): string
    {
        return mb_substr(__('chat.report.sheets.messages'), 0, 31);
    }
}
