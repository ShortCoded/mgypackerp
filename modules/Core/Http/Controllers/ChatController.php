<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Core\Http\Requests\StoreChatConversationRequest;
use Modules\Core\Http\Requests\StoreChatMessageRequest;
use Modules\Core\Models\ChatConversation;
use Modules\Core\Models\ChatMessage;
use Modules\Core\Models\ChatMessageAttachment;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\ChatService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private readonly BreadcrumbService $breadcrumbs,
        private readonly ChatService $chat,
    ) {}

    public function index(): View
    {
        return view('modules.core.chat.index', [
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('admin.chat.index'),
        ]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'conversations' => $this->chat->conversationPayloads($this->chat->conversationsFor($user), $user),
            ],
        ]);
    }

    public function storeConversation(StoreChatConversationRequest $request): JsonResponse
    {
        $conversation = $this->chat->createDirectConversation($request->user(), (string) $request->validated('user_doc_num'));

        return response()->json([
            'success' => true,
            'message' => __('chat.messages.conversation_ready'),
            'data' => [
                'conversation' => $this->chat->conversationPayload($conversation, $request->user()),
            ],
        ]);
    }

    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->chat->abortUnlessParticipant($conversation, $request->user());
        $messages = $this->chat->messagesFor($conversation, $request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'conversation' => $this->chat->conversationPayload($conversation, $request->user()),
                'messages' => $this->chat->messagePayloads($messages, $request->user()),
            ],
        ]);
    }

    public function info(Request $request, ChatConversation $conversation): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->chat->infoPayload($conversation, $request->user()),
        ]);
    }

    public function messages(Request $request, ChatConversation $conversation): JsonResponse
    {
        $messages = $this->chat->messagesFor(
            $conversation,
            $request->user(),
            $request->string('after')->toString(),
            $request->string('before')->toString(),
            (int) $request->integer('limit', 50),
        );

        return response()->json([
            'success' => true,
            'data' => [
                'messages' => $this->chat->messagePayloads($messages, $request->user()),
            ],
        ]);
    }

    public function storeMessage(StoreChatMessageRequest $request, ChatConversation $conversation): JsonResponse
    {
        $message = $this->chat->sendMessage(
            $conversation,
            $request->user(),
            $request->validated('body'),
            $request->file('attachments', []),
            $request->validated('reply_to_message_id'),
            null,
            $request->validated('client_message_id'),
        );

        return response()->json([
            'success' => true,
            'message' => __('chat.messages.sent'),
            'data' => [
                'message' => $this->chat->messagePayload($message, $request->user()),
                'conversation' => $this->chat->conversationPayload($conversation->refresh(), $request->user()),
            ],
        ]);
    }

    public function read(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->chat->markRead($conversation, $request->user());

        return response()->json([
            'success' => true,
            'message' => __('chat.messages.marked_read'),
        ]);
    }

    public function mute(Request $request, ChatConversation $conversation): JsonResponse
    {
        $muted = $this->chat->toggleMute($conversation, $request->user());

        return response()->json([
            'success' => true,
            'message' => $muted ? __('chat.chat_muted') : __('chat.chat_unmuted'),
            'data' => [
                'conversation' => $this->chat->conversationPayload($conversation->refresh(), $request->user()),
                'muted' => $muted,
            ],
        ]);
    }

    public function forward(Request $request, ChatMessage $message): JsonResponse
    {
        $validated = $request->validate([
            'user_doc_num' => ['required', 'string', 'max:255'],
        ], [], [
            'user_doc_num' => __('chat.fields.user'),
        ]);

        $forwarded = $this->chat->forwardMessage($message, $request->user(), (string) $validated['user_doc_num']);

        return response()->json([
            'success' => true,
            'message' => __('chat.message_forwarded'),
            'data' => [
                'message' => $this->chat->messagePayload($forwarded, $request->user()),
                'conversation' => $this->chat->conversationPayload($forwarded->conversation, $request->user()),
            ],
        ]);
    }

    public function attachment(Request $request, ChatMessageAttachment $attachment): StreamedResponse
    {
        $attachment->loadMissing('message.conversation');
        $this->chat->abortUnlessParticipant($attachment->message->conversation, $request->user());

        abort_unless(Storage::disk('local')->exists($attachment->file_path), 404);

        if (Str::startsWith((string) $attachment->mime_type, 'image/')) {
            return response()->stream(function () use ($attachment): void {
                echo Storage::disk('local')->get($attachment->file_path);
            }, 200, array_filter([
                'Content-Type' => $attachment->mime_type,
                'Content-Disposition' => 'inline; filename="'.$attachment->original_name.'"',
            ]));
        }

        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->original_name,
            array_filter(['Content-Type' => $attachment->mime_type]),
        );
    }
}
