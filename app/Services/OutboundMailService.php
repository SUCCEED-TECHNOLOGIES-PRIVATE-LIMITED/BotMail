<?php

namespace App\Services;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Inbox;
use App\Models\Message;
use App\Support\EmailAddress;
use Illuminate\Mail\Message as MailMessage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Throwable;

class OutboundMailService
{
    /**
     * @param  array{
     *     to: array<int, string>|string,
     *     cc?: array<int, string>|string|null,
     *     bcc?: array<int, string>|string|null,
     *     subject: string,
     *     text?: string|null,
     *     html?: string|null,
     *     attachments?: array<int, array<string, mixed>>|null,
     *     thread_id?: string|null,
     *     in_reply_to?: string|null,
     *     references?: string|null
     * }  $message
     */
    public function send(Inbox $inbox, array $message): Message
    {
        return $this->deliver($inbox, $message);
    }

    /**
     * @param  array{
     *     to?: array<int, string>|string|null,
     *     cc?: array<int, string>|string|null,
     *     bcc?: array<int, string>|string|null,
     *     subject?: string|null,
     *     text?: string|null,
     *     html?: string|null,
     *     attachments?: array<int, array<string, mixed>>|null
     * }  $reply
     */
    public function reply(Inbox $inbox, Message $original, array $reply): Message
    {
        $subject = trim((string) ($reply['subject'] ?? $original->subject));

        if (! str_starts_with(strtolower($subject), 're:')) {
            $subject = 'Re: '.$subject;
        }

        $references = trim(implode(' ', array_filter([
            $original->references,
            $original->message_id,
        ])));

        $to = $reply['to'] ?? null;

        if ($to === null || $to === '' || $to === []) {
            $to = [$original->from_address];
        }

        return $this->deliver($inbox, [
            'to' => $to,
            'cc' => $reply['cc'] ?? null,
            'bcc' => $reply['bcc'] ?? null,
            'subject' => $subject,
            'text' => $reply['text'] ?? null,
            'html' => $reply['html'] ?? null,
            'attachments' => $reply['attachments'] ?? null,
            'thread_id' => $original->thread_id ?: $original->message_id,
            'in_reply_to' => $original->message_id,
            'references' => $references !== '' ? $references : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function deliver(Inbox $inbox, array $message): Message
    {
        $inbox->loadMissing('domain');

        $to = EmailAddress::split($message['to'] ?? null);
        $cc = EmailAddress::split($message['cc'] ?? null);
        $bcc = EmailAddress::split($message['bcc'] ?? null);
        $subject = Str::limit(trim((string) ($message['subject'] ?? '')), 998, '');
        $html = isset($message['html']) && is_string($message['html']) && $message['html'] !== ''
            ? $message['html']
            : null;
        $text = isset($message['text']) && is_string($message['text']) && $message['text'] !== ''
            ? $message['text']
            : ($html !== null ? trim(html_entity_decode(strip_tags($html))) : null);
        $attachments = $this->normalizeAttachments($message['attachments'] ?? []);
        $messageId = $this->messageId($inbox);
        $threadId = is_string($message['thread_id'] ?? null) && $message['thread_id'] !== ''
            ? $message['thread_id']
            : $messageId;
        $inReplyTo = is_string($message['in_reply_to'] ?? null) ? $message['in_reply_to'] : null;
        $references = is_string($message['references'] ?? null) ? $message['references'] : null;

        $attributes = [
            'inbox_id' => $inbox->id,
            'direction' => MessageDirection::Outbound,
            'from_address' => $inbox->address,
            'to_address' => EmailAddress::join($to) ?? '',
            'cc' => EmailAddress::join($cc),
            'bcc' => EmailAddress::join($bcc),
            'subject' => $subject,
            'body_text' => $text,
            'body_html' => $html,
            'headers' => array_filter([
                'message-id' => $messageId,
                'in-reply-to' => $inReplyTo,
                'references' => $references,
            ]),
            'attachments' => $attachments,
            'thread_id' => $threadId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'is_read' => true,
            'message_id' => $messageId,
        ];

        try {
            $sent = Mail::mailer(config('mail.default'))->send([], [], function (MailMessage $mail) use ($inbox, $to, $cc, $bcc, $subject, $html, $text, $attachments, $messageId, $inReplyTo, $references): void {
                $mail->from($inbox->address, $inbox->display_name ?: $inbox->address)
                    ->to($to)
                    ->subject($subject);

                if ($cc !== []) {
                    $mail->cc($cc);
                }

                if ($bcc !== []) {
                    $mail->bcc($bcc);
                }

                if ($html !== null) {
                    $mail->html($html);
                }

                if ($text !== null) {
                    $mail->text($text);
                }

                $headers = $mail->getHeaders();
                $headers->addIdHeader('Message-ID', trim($messageId, '<>'));

                if ($inReplyTo !== null && $inReplyTo !== '') {
                    $headers->addTextHeader('In-Reply-To', $inReplyTo);
                }

                if ($references !== null && $references !== '') {
                    $headers->addTextHeader('References', $references);
                }

                foreach ($attachments as $attachment) {
                    $decoded = base64_decode($attachment['data'], true);

                    $mail->attachData(
                        $decoded === false ? '' : $decoded,
                        $attachment['filename'],
                        ['mime' => $attachment['mime']],
                    );
                }
            });

            $resendId = null;

            $original = $sent?->getOriginalMessage();

            if ($original instanceof Email) {
                $header = $original->getHeaders()->get('X-Resend-Email-ID');
                $body = $header?->getBodyAsString();
                $resendId = is_string($body) && $body !== '' ? $body : null;
            }

            return Message::query()->create([
                ...$attributes,
                'resend_id' => $resendId,
                'status' => MessageStatus::Sent,
            ]);
        } catch (Throwable $exception) {
            Message::query()->create([
                ...$attributes,
                'resend_id' => null,
                'status' => MessageStatus::Failed,
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<int, array{filename: string, mime: string, size: int, data: string}>
     */
    protected function normalizeAttachments(mixed $attachments): array
    {
        if (! is_array($attachments)) {
            return [];
        }

        $normalized = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['data'] ?? null)) {
                continue;
            }

            $decoded = base64_decode($attachment['data'], true);

            $normalized[] = [
                'filename' => is_string($attachment['filename'] ?? null) && $attachment['filename'] !== ''
                    ? $attachment['filename']
                    : 'attachment',
                'mime' => is_string($attachment['mime'] ?? null) && $attachment['mime'] !== ''
                    ? $attachment['mime']
                    : 'application/octet-stream',
                'size' => $decoded === false ? 0 : strlen($decoded),
                'data' => $attachment['data'],
            ];
        }

        return $normalized;
    }

    protected function messageId(Inbox $inbox): string
    {
        $domain = $inbox->domain?->domain ?: Str::after($inbox->address, '@');

        return '<'.Str::uuid().'@'.$domain.'>';
    }
}
