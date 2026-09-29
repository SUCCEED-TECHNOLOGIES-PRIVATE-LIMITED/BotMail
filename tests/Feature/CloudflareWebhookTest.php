<?php

namespace Tests\Feature;

use App\Models\Inbox;
use App\Models\Message;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'data' => ['viewer' => ['zones' => [['emailRoutingAdaptive' => []]]]],
            ]),
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

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'data' => ['viewer' => ['zones' => [['emailRoutingAdaptive' => []]]]],
            ]),
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

    public function test_cloudflare_spam_verdict_is_sent_as_spam(): void
    {
        config([
            'services.inbox_events.webhook_url' => 'https://marketing.example/api/webhooks/botmail',
            'services.inbox_events.webhook_secret' => 'whsec_dGVzdHNlY3JldA==',
        ]);

        Inbox::factory()->create([
            'address' => 'agent@example.com',
            'local_part' => 'agent',
        ]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'data' => [
                    'viewer' => [
                        'zones' => [[
                            'emailRoutingAdaptive' => [[
                                'messageId' => '<spam@example.com>',
                                'to' => 'agent@example.com',
                                'isSpam' => 1,
                            ]],
                        ]],
                    ],
                ],
            ]),
            'marketing.example/*' => Http::response('ok', 200),
        ]);

        $this->withHeader('X-Webhook-Secret', 'test-webhook-secret')
            ->postJson('/webhook/cloudflare', [
                'from' => 'ada@example.com',
                'to' => 'agent@example.com',
                'subject' => 'Hello',
                'text' => 'Hi',
                'message_id' => '<spam@example.com>',
            ])
            ->assertStatus(202);

        $this->assertDatabaseHas('messages', [
            'message_id' => '<spam@example.com>',
            'is_spam' => true,
        ]);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'marketing.example')) {
                return false;
            }

            $body = $request->body();

            return str_contains($body, 'message.received.spam')
                && str_contains($body, '"spam"');
        });
    }

    public function test_clean_cloudflare_verdict_stays_inbox(): void
    {
        Inbox::factory()->create([
            'address' => 'agent@example.com',
            'local_part' => 'agent',
        ]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'data' => [
                    'viewer' => [
                        'zones' => [[
                            'emailRoutingAdaptive' => [[
                                'messageId' => '<clean@example.com>',
                                'to' => 'agent@example.com',
                                'isSpam' => 0,
                            ]],
                        ]],
                    ],
                ],
            ]),
        ]);

        $this->withHeader('X-Webhook-Secret', 'test-webhook-secret')
            ->postJson('/webhook/cloudflare', [
                'from' => 'ada@example.com',
                'to' => 'agent@example.com',
                'subject' => 'Hello',
                'text' => 'Hi',
                'message_id' => '<clean@example.com>',
            ])
            ->assertStatus(202);

        $this->assertDatabaseHas('messages', [
            'message_id' => '<clean@example.com>',
            'is_spam' => false,
        ]);
    }
}
