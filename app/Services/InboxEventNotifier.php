<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class InboxEventNotifier
{
    public function received(Message $message): void
    {
        $this->dispatch('message.received', [
            'message' => $this->messageFields($message),
        ]);
    }

    public function bounced(Message $message, string $reason): void
    {
        $this->dispatch('message.bounced', [
            'bounce' => $this->deliveryFields($message, $reason),
        ]);
    }

    public function rejected(Message $message, string $reason): void
    {
        $this->dispatch('message.rejected', [
            'reject' => $this->deliveryFields($message, $reason),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function dispatch(string $eventType, array $body): void
    {
        $url = config('services.inbox_events.webhook_url');
        $secret = config('services.inbox_events.webhook_secret');

        if (! is_string($url) || $url === '' || ! is_string($secret) || $secret === '') {
            return;
        }

        $payload = json_encode([
            'event_id' => 'evt_'.Str::lower(Str::random(24)),
            'event_type' => $eventType,
            ...$body,
        ], JSON_UNESCAPED_SLASHES);

        if (! is_string($payload)) {
            return;
        }

        $id = 'msg_'.Str::lower(Str::random(24));
        $timestamp = (string) time();
        $signature = base64_encode(hash_hmac(
            'sha256',
            $id.'.'.$timestamp.'.'.$payload,
            $this->secretBytes($secret),
            true,
        ));

        try {
            Http::timeout(10)
                ->withBody($payload, 'application/json')
                ->withHeaders([
                    'svix-id' => $id,
                    'svix-timestamp' => $timestamp,
                    'svix-signature' => 'v1,'.$signature,
                ])
                ->post($url);
        } catch (Throwable $exception) {
            Log::warning('Inbox webhook failed', [
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function messageFields(Message $message): array
    {
        $message->loadMissing('inbox');

        return [
            'inbox_id' => $message->inbox?->address,
            'message_id' => $message->message_id ?: (string) $message->id,
            'thread_id' => $message->thread_id,
            'from' => $message->from_address,
            'in_reply_to' => $message->in_reply_to,
            'labels' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function deliveryFields(Message $message, string $reason): array
    {
        $message->loadMissing('inbox');

        return [
            'inbox_id' => $message->inbox?->address,
            'message_id' => $message->message_id ?: (string) $message->id,
            'thread_id' => $message->thread_id,
            'reason' => $reason,
        ];
    }

    protected function secretBytes(string $secret): string
    {
        $encoded = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $decoded = base64_decode($encoded, true);

        return is_string($decoded) && $decoded !== '' ? $decoded : $secret;
    }
}
