<?php

namespace App\Jobs;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\WebhookLog;
use App\Services\InboxEventNotifier;
use App\Support\EmailAddress;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class ProcessInboundEmail implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload, public int $webhookLogId) {}

    public function handle(): void
    {
        try {
            $this->process();
        } catch (Throwable $exception) {
            WebhookLog::query()->whereKey($this->webhookLogId)->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    protected function process(): void
    {
        $headers = $this->normalizeHeaders($this->payload['headers'] ?? []);
        $addresses = EmailAddress::split($this->payload['to'] ?? $this->payload['recipients'] ?? null);
        $inbox = null;
        $matchedAddress = null;

        foreach ($addresses as $address) {
            $candidate = Inbox::query()
                ->where('address', $address)
                ->where('status', 'active')
                ->first();

            if ($candidate !== null) {
                $inbox = $candidate;
                $matchedAddress = $address;
                break;
            }
        }

        if ($inbox === null) {
            WebhookLog::query()->whereKey($this->webhookLogId)->update([
                'status' => 'ignored',
                'error' => 'No active inbox for '.implode(', ', $addresses),
            ]);

            return;
        }

        $messageId = $this->messageId($headers);

        if ($messageId !== null && Message::query()
            ->where('inbox_id', $inbox->id)
            ->where('message_id', $messageId)
            ->exists()) {
            WebhookLog::query()->whereKey($this->webhookLogId)->update([
                'status' => 'duplicate',
            ]);

            return;
        }

        $inReplyTo = $this->headerValue($headers, 'in-reply-to');
        $references = $this->headerValue($headers, 'references');
        $threadId = $this->threadId($inbox, $messageId, $inReplyTo, $references);

        $message = Message::query()->create([
            'inbox_id' => $inbox->id,
            'direction' => MessageDirection::Inbound,
            'from_address' => EmailAddress::normalize(
                is_string($this->payload['from'] ?? null) ? $this->payload['from'] : null,
            ) ?? 'unknown@unknown',
            'to_address' => $matchedAddress,
            'cc' => EmailAddress::join(EmailAddress::split($this->payload['cc'] ?? null)),
            'bcc' => EmailAddress::join(EmailAddress::split($this->payload['bcc'] ?? null)),
            'subject' => Str::limit(is_string($this->payload['subject'] ?? null) ? $this->payload['subject'] : '(no subject)', 998, ''),
            'body_text' => is_string($this->payload['text'] ?? null) ? $this->payload['text'] : null,
            'body_html' => is_string($this->payload['html'] ?? null) ? $this->payload['html'] : null,
            'headers' => $headers,
            'attachments' => $this->attachments(),
            'thread_id' => $threadId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'is_read' => false,
            'resend_id' => null,
            'status' => MessageStatus::Received,
            'message_id' => $messageId,
        ]);

        app(InboxEventNotifier::class)->received($message);

        WebhookLog::query()->whereKey($this->webhookLogId)->update([
            'status' => 'processed',
            'error' => null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function normalizeHeaders(mixed $headers): array
    {
        if (! is_array($headers)) {
            return [];
        }

        $normalized = [];

        foreach ($headers as $key => $value) {
            if (is_array($value) && (isset($value['key']) || isset($value['name']))) {
                $name = strtolower((string) ($value['key'] ?? $value['name']));
                $normalized[$name] = is_scalar($value['value'] ?? null) ? (string) $value['value'] : '';

                continue;
            }

            $name = strtolower((string) $key);
            $normalized[$name] = is_array($value)
                ? implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value))
                : (is_scalar($value) ? (string) $value : '');
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function messageId(array $headers): ?string
    {
        $messageId = $this->payload['message_id'] ?? $headers['message-id'] ?? null;

        if (! is_string($messageId)) {
            return null;
        }

        $messageId = trim($messageId);

        return $messageId === '' ? null : $messageId;
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected function headerValue(array $headers, string $name): ?string
    {
        $value = trim($headers[$name] ?? '');

        return $value === '' ? null : $value;
    }

    protected function threadId(Inbox $inbox, ?string $messageId, ?string $inReplyTo, ?string $references): string
    {
        $candidates = array_filter(array_merge(
            [$inReplyTo],
            preg_split('/\s+/', (string) $references) ?: [],
        ));

        foreach ($candidates as $candidate) {
            $existing = Message::query()
                ->where('inbox_id', $inbox->id)
                ->where('message_id', trim((string) $candidate))
                ->first();

            if ($existing !== null) {
                return $existing->thread_id ?: ($existing->message_id ?: (string) Str::uuid());
            }
        }

        return $messageId ?: (string) Str::uuid();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function attachments(): array
    {
        $attachments = $this->payload['attachments'] ?? [];

        if (! is_array($attachments)) {
            return [];
        }

        $normalized = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $data = is_string($attachment['data'] ?? null) ? $attachment['data'] : '';
            $decoded = $data === '' ? false : base64_decode($data, true);

            $normalized[] = [
                'filename' => is_string($attachment['filename'] ?? null) && $attachment['filename'] !== ''
                    ? $attachment['filename']
                    : 'attachment',
                'mime' => is_string($attachment['mime'] ?? null) && $attachment['mime'] !== ''
                    ? $attachment['mime']
                    : 'application/octet-stream',
                'size' => isset($attachment['size']) ? (int) $attachment['size'] : ($decoded === false ? 0 : strlen($decoded)),
                'data' => $data,
            ];
        }

        return $normalized;
    }
}
