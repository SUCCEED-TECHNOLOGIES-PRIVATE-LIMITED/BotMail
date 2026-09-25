<?php

namespace Tests\Feature;

use App\Models\Inbox;
use App\Models\Message;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloudflareWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_a_bad_secret(): void
    {
        $response = $this->postJson('/webhook/cloudflare', [
            'from' => 'sender@example.com',
            'to' => 'agent@example.com',
            'subject' => 'Hello',
            'text' => 'Hi',
        ], [
            'X-Webhook-Secret' => 'wrong',
        ]);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_webhook_stores_an_inbound_message(): void
    {
        $inbox = Inbox::factory()->create([
            'address' => 'agent@example.com',
            'local_part' => 'agent',
        ]);

        $response = $this->postJson('/webhook/cloudflare', [
            'from' => 'Ada Lovelace <ada@example.com>',
            'to' => 'agent@example.com',
            'subject' => 'Hello',
            'text' => 'Hi there',
            'html' => '<p>Hi there</p>',
            'message_id' => '<abc@example.com>',
            'headers' => [
                ['key' => 'Message-ID', 'value' => '<abc@example.com>'],
            ],
            'attachments' => [
                [
                    'filename' => 'note.txt',
                    'mime' => 'text/plain',
                    'size' => 5,
                    'data' => base64_encode('hello'),
                ],
            ],
        ], [
            'X-Webhook-Secret' => 'test-webhook-secret',
        ]);

        $response->assertStatus(202);

        $this->assertDatabaseHas('messages', [
            'inbox_id' => $inbox->id,
            'direction' => 'inbound',
            'from_address' => 'ada@example.com',
            'to_address' => 'agent@example.com',
            'subject' => 'Hello',
            'message_id' => '<abc@example.com>',
            'is_read' => false,
            'status' => 'received',
        ]);

        $message = Message::query()->first();
        $this->assertSame('hello', base64_decode($message->attachments[0]['data']));

        $log = WebhookLog::query()->first();
        $this->assertSame('processed', $log->status);
        $this->assertStringNotContainsString(base64_encode('hello'), json_encode($log->payload));
    }

    public function test_webhook_skips_duplicate_message_ids(): void
    {
        Inbox::factory()->create([
            'address' => 'agent@example.com',
            'local_part' => 'agent',
        ]);

        $payload = [
            'from' => 'ada@example.com',
            'to' => 'agent@example.com',
            'subject' => 'Hello',
            'text' => 'Hi',
            'message_id' => '<same@example.com>',
        ];

        $this->withHeader('X-Webhook-Secret', 'test-webhook-secret')
            ->postJson('/webhook/cloudflare', $payload)
            ->assertStatus(202);

        $this->withHeader('X-Webhook-Secret', 'test-webhook-secret')
            ->postJson('/webhook/cloudflare', $payload)
            ->assertStatus(202);

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('webhook_logs', ['status' => 'duplicate']);
    }

    public function test_webhook_ignores_unknown_recipients(): void
    {
        $this->withHeader('X-Webhook-Secret', 'test-webhook-secret')
            ->postJson('/webhook/cloudflare', [
                'from' => 'ada@example.com',
                'to' => 'missing@example.com',
                'subject' => 'Hello',
                'text' => 'Hi',
            ])
            ->assertStatus(202);

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseHas('webhook_logs', ['status' => 'ignored']);
    }
}
