<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageDirection;
use App\Exceptions\CloudflareApiException;
use App\Exceptions\InboxLimitException;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Inbox;
use App\Models\Message;
use App\Services\InboxService;
use App\Services\OutboundMailService;
use App\Support\EmailAddress;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class V0InboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = $this->limit($request);
        $inboxes = Inbox::query()->with('domain')->latest('id')->limit($limit)->get();

        return response()->json([
            'count' => $inboxes->count(),
            'limit' => $limit,
            'inboxes' => $inboxes->map(fn (Inbox $inbox): array => $this->inboxPayload($inbox))->all(),
        ]);
    }

    public function store(Request $request, InboxService $inboxes): JsonResponse
    {
        $clientId = trim((string) $request->input('client_id', ''));

        if ($clientId !== '') {
            $existing = Inbox::query()->where('client_id', $clientId)->first();

            if ($existing !== null) {
                return response()->json($this->inboxPayload($existing));
            }
        }

        $domainName = strtolower(trim((string) $request->input('domain', '')));
        $domain = $domainName !== ''
            ? Domain::query()->where('domain', $domainName)->first()
            : Domain::query()->whereNotNull('verified_at')->orderBy('id')->first()
                ?? Domain::query()->orderBy('id')->first();

        if ($domain === null) {
            return $this->error(422, 'No verified domain is available.', 'domain_not_found');
        }

        $username = strtolower(trim((string) $request->input('username', '')));

        if ($username === '') {
            $username = 'warmer'.Str::lower(Str::random(8));
        }

        if (preg_match('/^[a-z0-9][a-z0-9._+-]*$/', $username) !== 1) {
            return $this->error(422, 'Username must be a valid mailbox name.', 'invalid_username');
        }

        try {
            $inbox = $inboxes->create([
                'domain_id' => $domain->id,
                'local_part' => $username,
                'display_name' => $request->input('display_name'),
            ]);
        } catch (InboxLimitException|CloudflareApiException $exception) {
            return $this->error(422, $exception->getMessage(), 'inbox_create_failed');
        } catch (QueryException) {
            return $this->error(409, 'That inbox already exists.', 'inbox_exists');
        }

        $metadata = $request->input('metadata');
        $inbox->update([
            'client_id' => $clientId !== '' ? $clientId : null,
            'metadata' => is_array($metadata) ? $metadata : null,
        ]);

        return response()->json($this->inboxPayload($inbox->refresh()));
    }

    public function show(string $inboxId): JsonResponse
    {
        $inbox = $this->findInbox($inboxId);

        if ($inbox === null) {
            return $this->error(404, 'Inbox not found.', 'not_found');
        }

        return response()->json($this->inboxPayload($inbox));
    }

    public function destroy(string $inboxId, InboxService $inboxes): JsonResponse
    {
        $inbox = $this->findInbox($inboxId);

        if ($inbox === null) {
            return $this->error(404, 'Inbox not found.', 'not_found');
        }

        try {
            $inboxes->delete($inbox);
        } catch (CloudflareApiException $exception) {
            return $this->error(422, $exception->getMessage(), 'inbox_delete_failed');
        }

        return response()->json(null, 204);
    }

    public function messages(Request $request, string $inboxId): JsonResponse
    {
        $inbox = $this->findInbox($inboxId);

        if ($inbox === null) {
            return $this->error(404, 'Inbox not found.', 'not_found');
        }

        $limit = $this->limit($request);
        $messages = $inbox->messages()->forList()->latest('id')->limit($limit)->get();

        return response()->json([
            'count' => $messages->count(),
            'limit' => $limit,
            'messages' => $messages->map(fn (Message $message): array => $this->messagePayload($message, $inbox, detailed: false))->all(),
        ]);
    }

    public function message(string $inboxId, string $messageId): JsonResponse
    {
        $inbox = $this->findInbox($inboxId);

        if ($inbox === null) {
            return $this->error(404, 'Inbox not found.', 'not_found');
        }

        $message = $this->findMessage($inbox, $messageId);

        if ($message === null) {
            return $this->error(404, 'Message not found.', 'not_found');
        }

        return response()->json($this->messagePayload($message, $inbox, detailed: true));
    }

    public function send(Request $request, string $inboxId, OutboundMailService $mail): JsonResponse
    {
        $inbox = $this->findInbox($inboxId);

        if ($inbox === null) {
            return $this->error(404, 'Inbox not found.', 'not_found');
        }

        $text = $request->input('text');
        $html = $request->input('html');

        if ((! is_string($text) || $text === '') && (! is_string($html) || $html === '')) {
            return $this->error(422, 'A text or html body is required.', 'missing_body');
        }

        $inReplyTo = $this->header($request, 'In-Reply-To');
        $references = $this->header($request, 'References');
        $threadId = null;

        if ($inReplyTo !== null) {
            $existing = Message::query()->where('message_id', $inReplyTo)->first();
            $threadId = $existing?->thread_id ?: $inReplyTo;
        }

        try {
            $message = $mail->send($inbox, [
                'to' => $request->input('to'),
                'cc' => $request->input('cc'),
                'bcc' => $request->input('bcc'),
                'subject' => (string) $request->input('subject', ''),
                'text' => is_string($text) ? $text : null,
                'html' => is_string($html) ? $html : null,
                'attachments' => $this->attachments($request->input('attachments')),
                'thread_id' => $threadId,
                'in_reply_to' => $inReplyTo,
                'references' => $references,
            ]);
        } catch (Throwable $exception) {
            return $this->error(422, $exception->getMessage(), 'send_failed');
        }

        return response()->json([
            'message_id' => $message->message_id,
            'thread_id' => $message->thread_id,
        ]);
    }

    public function reply(Request $request, string $inboxId, string $messageId, OutboundMailService $mail): JsonResponse
    {
        $inbox = $this->findInbox($inboxId);

        if ($inbox === null) {
            return $this->error(404, 'Inbox not found.', 'not_found');
        }

        $original = $this->findMessage($inbox, $messageId);

        if ($original === null) {
            return $this->error(404, 'Message not found.', 'not_found');
        }

        try {
            $reply = $mail->reply($inbox, $original, [
                'to' => $request->input('to'),
                'cc' => $request->input('cc'),
                'bcc' => $request->input('bcc'),
                'subject' => $request->input('subject'),
                'text' => $request->input('text'),
                'html' => $request->input('html'),
                'attachments' => $this->attachments($request->input('attachments')),
            ]);
        } catch (Throwable $exception) {
            return $this->error(422, $exception->getMessage(), 'send_failed');
        }

        return response()->json([
            'message_id' => $reply->message_id,
            'thread_id' => $reply->thread_id,
        ]);
    }

    protected function findInbox(string $inboxId): ?Inbox
    {
        $inboxId = urldecode($inboxId);
        $query = Inbox::query()->with('domain');

        if (ctype_digit($inboxId)) {
            return $query->find($inboxId);
        }

        return $query->where('address', EmailAddress::normalize($inboxId))->first();
    }

    protected function findMessage(Inbox $inbox, string $messageId): ?Message
    {
        $messageId = urldecode($messageId);
        $query = $inbox->messages();

        if (ctype_digit($messageId)) {
            return $query->where('id', $messageId)->first();
        }

        return $query->where('message_id', $messageId)->first();
    }

    /**
     * @return list<string>
     */
    protected function labels(Message $message, ?MessageDirection $direction): array
    {
        if ($direction === MessageDirection::Outbound) {
            return ['sent'];
        }

        return $message->is_spam ? ['spam'] : [];
    }

    protected function inboxPayload(Inbox $inbox): array
    {
        return [
            'inbox_id' => $inbox->address,
            'email' => $inbox->address,
            'display_name' => $inbox->display_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function messagePayload(Message $message, Inbox $inbox, bool $detailed): array
    {
        $direction = $message->direction instanceof MessageDirection
            ? $message->direction
            : MessageDirection::tryFrom((string) $message->direction);

        $payload = [
            'message_id' => $message->message_id ?: (string) $message->id,
            'thread_id' => $message->thread_id,
            'inbox_id' => $inbox->address,
            'from' => $message->from_address,
            'to' => EmailAddress::split($message->to_address),
            'cc' => EmailAddress::split($message->cc),
            'bcc' => EmailAddress::split($message->bcc),
            'subject' => $message->subject,
            'from_' => $message->from_address,
            'preview' => Str::limit(trim((string) $message->body_text), 140, ''),
            'timestamp' => $message->created_at?->toIso8601String(),
            'labels' => $this->labels($message, $direction),
        ];

        if (! $detailed) {
            return $payload;
        }

        $payload['text'] = $message->body_text;
        $payload['html'] = $message->body_html;
        $payload['attachments'] = collect($message->attachments ?? [])
            ->map(function (mixed $file): array {
                $file = is_array($file) ? $file : [];

                return [
                    'filename' => $file['filename'] ?? 'attachment',
                    'content_type' => $file['mime'] ?? 'application/octet-stream',
                    'content' => $file['data'] ?? null,
                ];
            })
            ->all();

        return $payload;
    }

    /**
     * @return array<int, array{filename: string, mime: string, data: string}>
     */
    protected function attachments(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        $normalized = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $data = $attachment['content'] ?? $attachment['data'] ?? null;

            if (! is_string($data) || $data === '') {
                continue;
            }

            $normalized[] = [
                'filename' => is_string($attachment['filename'] ?? null) && $attachment['filename'] !== ''
                    ? $attachment['filename']
                    : 'attachment',
                'mime' => is_string($attachment['content_type'] ?? null) && $attachment['content_type'] !== ''
                    ? $attachment['content_type']
                    : (is_string($attachment['mime'] ?? null) && $attachment['mime'] !== ''
                        ? $attachment['mime']
                        : 'application/octet-stream'),
                'data' => $data,
            ];
        }

        return $normalized;
    }

    protected function header(Request $request, string $name): ?string
    {
        $headers = $request->input('headers');

        if (! is_array($headers)) {
            return null;
        }

        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0 || ! is_string($value)) {
                continue;
            }

            $value = trim($value);

            return $value === '' ? null : $value;
        }

        return null;
    }

    protected function limit(Request $request): int
    {
        return min(100, max(1, (int) $request->query('limit', $request->query('per_page', 25))));
    }

    protected function error(int $status, string $message, string $code): JsonResponse
    {
        return response()->json([
            'name' => 'Error',
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
