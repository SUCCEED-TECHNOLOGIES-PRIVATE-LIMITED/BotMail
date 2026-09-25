<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Http\Resources\MessageSummaryResource;
use App\Models\Inbox;
use App\Models\Message;
use App\Services\OutboundMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

class MessageController extends Controller
{
    public function index(Request $request, Inbox $inbox): AnonymousResourceCollection
    {
        $messages = $inbox->messages()
            ->forList()
            ->when($request->filled('direction'), fn ($query) => $query->where('direction', $request->string('direction')))
            ->when($request->boolean('unread'), fn ($query) => $query->where('is_read', false))
            ->latest('id')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return MessageSummaryResource::collection($messages);
    }

    public function show(Inbox $inbox, Message $message): MessageResource
    {
        return new MessageResource($message);
    }

    public function store(Request $request, Inbox $inbox, OutboundMailService $mail): JsonResponse|MessageResource
    {
        $data = $this->validateMessage($request, requireRecipient: true);

        try {
            $message = $mail->send($inbox, $data);
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    public function reply(Request $request, Inbox $inbox, Message $message, OutboundMailService $mail): JsonResponse|MessageResource
    {
        $data = $this->validateMessage($request, requireRecipient: false);

        try {
            $reply = $mail->reply($inbox, $message, $data);
        } catch (Throwable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return (new MessageResource($reply))->response()->setStatusCode(201);
    }

    public function destroy(Inbox $inbox, Message $message): JsonResponse
    {
        $message->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validateMessage(Request $request, bool $requireRecipient): array
    {
        $recipientRule = $requireRecipient ? ['required', 'array', 'min:1'] : ['nullable', 'array'];

        return $request->validate([
            'to' => $recipientRule,
            'to.*' => ['email'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['nullable', 'array'],
            'bcc.*' => ['email'],
            'subject' => [$requireRecipient ? 'required' : 'nullable', 'string', 'max:998'],
            'text' => ['nullable', 'string', 'required_without:html'],
            'html' => ['nullable', 'string', 'required_without:text'],
            'attachments' => ['nullable', 'array'],
            'attachments.*.filename' => ['required', 'string', 'max:255'],
            'attachments.*.mime' => ['required', 'string', 'max:255'],
            'attachments.*.data' => ['required', 'string'],
            'direction' => ['prohibited'],
        ]);
    }
}
