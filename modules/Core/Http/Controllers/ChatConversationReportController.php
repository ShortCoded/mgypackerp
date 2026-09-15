<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Core\Exports\ChatConversationReportExport;
use Modules\Core\Http\Requests\ChatConversationReportRequest;
use Modules\Core\Models\ChatConversation;
use Modules\Core\Models\ChatMessageAttachment;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\ChatConversationReportService;
use Modules\Core\Services\Reports\ReportPdfService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatConversationReportController extends Controller
{
    public function __construct(
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ChatConversationReportService $report,
        private readonly ReportPdfService $pdf,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function index(ChatConversationReportRequest $request): View
    {
        $filters = $request->validated();
        $conversations = $this->report->paginate($filters);
        $statistics = $this->report->statistics($filters);
        $this->logAccess($request, 'view', 'all', $statistics['conversations']);

        return view('modules.core.chat.reports.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.chat.reports.index'),
            'conversations' => $conversations,
            'statistics' => $statistics,
            'filters' => $filters,
            'report' => $this->report,
        ]);
    }

    public function show(Request $request, ChatConversation $conversation): View
    {
        $conversation = $this->report->find((string) $conversation->public_uuid);
        $messages = $this->report->paginateMessages($conversation);
        $this->logAccess($request, 'view', (string) $conversation->public_uuid, $messages->total());

        return view('modules.core.chat.reports.show', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.chat.reports.index'),
            'conversation' => $conversation,
            'messages' => $messages,
            'report' => $this->report,
        ]);
    }

    public function excel(ChatConversationReportRequest $request): BinaryFileResponse
    {
        $filters = $request->validated();
        $this->logAccess($request, 'export_excel', 'all');

        return Excel::download(
            new ChatConversationReportExport($filters),
            'chat-conversations-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function conversationExcel(Request $request, ChatConversation $conversation): BinaryFileResponse
    {
        $this->logAccess($request, 'export_excel', (string) $conversation->public_uuid);

        return Excel::download(
            new ChatConversationReportExport(['conversation_uuid' => $conversation->public_uuid]),
            'chat-conversation-'.$this->safeIdentifier($conversation).'-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    public function pdf(ChatConversationReportRequest $request): Response
    {
        $filters = $request->validated();
        $conversations = $this->report->transcript($filters);
        $this->logAccess($request, 'export_pdf', 'all', $conversations->count());

        return $this->pdf->stream('reports.chat-conversations', [
            'title' => __('chat.report.title'),
            'conversations' => $conversations,
            'report' => $this->report,
            'filters' => $filters,
        ], 'chat-conversations-'.now()->format('Ymd-His').'.pdf', 'P');
    }

    public function conversationPdf(Request $request, ChatConversation $conversation): Response
    {
        $conversations = $this->report->transcript(['conversation_uuid' => $conversation->public_uuid]);
        $this->logAccess($request, 'export_pdf', (string) $conversation->public_uuid, $conversations->count());

        return $this->pdf->stream('reports.chat-conversations', [
            'title' => __('chat.report.single_title', ['conversation' => $this->report->title($conversations->firstOrFail())]),
            'conversations' => $conversations,
            'report' => $this->report,
            'filters' => [],
        ], 'chat-conversation-'.$this->safeIdentifier($conversation).'-'.now()->format('Ymd-His').'.pdf', 'P');
    }

    public function print(ChatConversationReportRequest $request): View
    {
        $filters = $request->validated();
        $conversations = $this->report->transcript($filters);
        $this->logAccess($request, 'print', 'all', $conversations->count());

        return view('modules.core.chat.reports.print', [
            'title' => __('chat.report.title'),
            'conversations' => $conversations,
            'report' => $this->report,
            'filters' => $filters,
        ]);
    }

    public function conversationPrint(Request $request, ChatConversation $conversation): View
    {
        $conversations = $this->report->transcript(['conversation_uuid' => $conversation->public_uuid]);
        $this->logAccess($request, 'print', (string) $conversation->public_uuid, $conversations->count());

        return view('modules.core.chat.reports.print', [
            'title' => __('chat.report.single_title', ['conversation' => $this->report->title($conversations->firstOrFail())]),
            'conversations' => $conversations,
            'report' => $this->report,
            'filters' => [],
        ]);
    }

    public function attachment(Request $request, ChatMessageAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);
        $attachment->loadMissing('message.conversation');
        $this->logAccess($request, 'attachment', (string) $attachment->message?->conversation?->public_uuid);

        $inlineImageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

        if (in_array(Str::lower((string) $attachment->mime_type), $inlineImageTypes, true)) {
            $filename = (string) Str::of((string) $attachment->original_name)
                ->replace('\\', '/')
                ->afterLast('/')
                ->replace(["\r", "\n"], '');
            $filename = $filename !== '' ? $filename : 'chat-attachment';
            $fallbackFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($filename)) ?: 'chat-attachment';
            $contentDisposition = (new ResponseHeaderBag)->makeDisposition(
                ResponseHeaderBag::DISPOSITION_INLINE,
                $filename,
                $fallbackFilename,
            );

            return response()->stream(function () use ($attachment): void {
                $stream = Storage::disk('local')->readStream($attachment->file_path);

                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, 200, array_filter([
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => $contentDisposition,
                'X-Content-Type-Options' => 'nosniff',
            ]));
        }

        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->original_name,
            array_filter([
                'Content-Type' => $attachment->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ]),
        );
    }

    private function safeIdentifier(ChatConversation $conversation): string
    {
        return Str::lower(Str::substr((string) $conversation->public_uuid, 0, 12));
    }

    private function logAccess(Request $request, string $operation, string $scope, ?int $recordCount = null): void
    {
        $this->activityLogger->log($request, 'core', 'chat.report.'.$operation, 'success', [
            'properties_only' => true,
            'url' => $request->url(),
            'properties' => array_filter([
                'scope' => $scope,
                'record_count' => $recordCount,
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }
}
