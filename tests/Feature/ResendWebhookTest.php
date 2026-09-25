<?php

namespace Tests\Feature;

use App\Models\Inbox;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResendWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_resend_webhook_updates_delivery_status(): void
    {
        $inbox = Inbox::factory()->create();

        $message = Message::query()->create([
            'inbox_id' => $inbox->id,
            'direction' => 'outbound',
            'from_address' => $inbox->address,
            'to_address' => 'ada@example.com',
            'subject' => 'Hello',
            'body_text' => 'Hi',
            'is_read' => true,
            'status' => 'sent',
            'resend_id' => 'email_123',
            'message_id' => '<outbound@example.com>',
            'thread_id' => '<outbound@example.com>',
        ]);

        $payload = json_encode([
            'type' => 'email.delivered',
            'data' => [
                'email_id' => 'email_123',
            ],
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/webhook/resend',
            [],
            [],
            [],
            $this->signedHeaders($payload),
            $payload,
        )->assertOk();

        $this->assertSame('delivered', $message->fresh()->status->value);
    }

    public function test_resend_webhook_rejects_a_bad_signature(): void
    {
        $this->postJson('/webhook/resend', [
            'type' => 'email.delivered',
            'data' => ['email_id' => 'email_123'],
        ], [
            'svix-id' => 'msg_test',
            'svix-timestamp' => (string) time(),
            'svix-signature' => 'v1,invalid',
        ])->assertUnauthorized();
    }

    /**
     * @return array<string, string>
     */
    protected function signedHeaders(string $payload): array
    {
        $secret = base64_decode(substr('whsec_dGVzdHNlY3JldA==', 6));
        $id = 'msg_test';
        $timestamp = (string) time();
        $signature = base64_encode(pack('H*', hash_hmac('sha256', "{$id}.{$timestamp}.{$payload}", $secret)));

        return [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_SVIX_ID' => $id,
            'HTTP_SVIX_TIMESTAMP' => $timestamp,
            'HTTP_SVIX_SIGNATURE' => 'v1,'.$signature,
        ];
    }
}
