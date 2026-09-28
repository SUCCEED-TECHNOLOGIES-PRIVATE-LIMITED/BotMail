<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Inbox;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class V0ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_bearer_token_creates_lists_and_sends(): void
    {
        Domain::factory()->create([
            'domain' => 'oneloqin.xyz',
            'cloudflare_zone_id' => 'zone_123',
            'verified_at' => now(),
        ]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['id' => 'rule_123'],
            ]),
        ]);

        $this->withToken('test-api-key')
            ->postJson('/v0/inboxes', [
                'username' => 'agent0',
                'domain' => 'oneloqin.xyz',
                'display_name' => 'Agent',
            ])
            ->assertOk()
            ->assertJsonPath('inbox_id', 'agent0@oneloqin.xyz')
            ->assertJsonPath('email', 'agent0@oneloqin.xyz');

        $this->withToken('test-api-key')
            ->getJson('/v0/inboxes')
            ->assertOk()
            ->assertJsonPath('inboxes.0.inbox_id', 'agent0@oneloqin.xyz');

        $sent = $this->withToken('test-api-key')
            ->postJson('/v0/inboxes/agent0@oneloqin.xyz/messages/send', [
                'to' => 'person@example.com',
                'subject' => 'Hello',
                'text' => 'Hi',
                'attachments' => [
                    [
                        'filename' => 'note.txt',
                        'content_type' => 'text/plain',
                        'content' => base64_encode('hello'),
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonStructure(['message_id', 'thread_id']);

        $messageId = $sent->json('message_id');

        $this->withToken('test-api-key')
            ->getJson('/v0/inboxes/agent0@oneloqin.xyz/messages')
            ->assertOk()
            ->assertJsonPath('messages.0.message_id', $messageId)
            ->assertJsonMissingPath('messages.0.text');

        $this->withToken('test-api-key')
            ->getJson('/v0/inboxes/agent0@oneloqin.xyz/messages/'.rawurlencode((string) $messageId))
            ->assertOk()
            ->assertJsonPath('text', 'Hi')
            ->assertJsonPath('attachments.0.filename', 'note.txt');
    }

    public function test_reply_uses_the_message_id(): void
    {
        $inbox = Inbox::factory()->create([
            'address' => 'agent0@oneloqin.xyz',
            'local_part' => 'agent0',
        ]);

        Message::query()->create([
            'inbox_id' => $inbox->id,
            'direction' => 'inbound',
            'from_address' => 'ada@example.com',
            'to_address' => 'agent0@oneloqin.xyz',
            'subject' => 'Question',
            'body_text' => 'Hello',
            'thread_id' => '<question@oneloqin.xyz>',
            'message_id' => '<question@oneloqin.xyz>',
            'is_read' => false,
            'status' => 'received',
        ]);

        $this->withToken('test-api-key')
            ->postJson('/v0/inboxes/agent0@oneloqin.xyz/messages/'.rawurlencode('<question@oneloqin.xyz>').'/reply', [
                'text' => 'Thanks',
            ])
            ->assertOk()
            ->assertJsonPath('thread_id', '<question@oneloqin.xyz>');

        $this->assertDatabaseHas('messages', [
            'inbox_id' => $inbox->id,
            'subject' => 'Re: Question',
            'in_reply_to' => '<question@oneloqin.xyz>',
        ]);
    }

    public function test_missing_bearer_token_is_forbidden(): void
    {
        $this->getJson('/v0/inboxes')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden']);
    }

    public function test_client_id_returns_the_same_inbox(): void
    {
        Domain::factory()->create([
            'domain' => 'oneloqin.xyz',
            'cloudflare_zone_id' => 'zone_123',
            'verified_at' => now(),
        ]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['id' => 'rule_123'],
            ]),
        ]);

        $payload = [
            'client_id' => 'succeedreach-warmer-01jabc',
            'display_name' => 'SucceedReach Warmer',
            'metadata' => ['source' => 'succeedreach', 'purpose' => 'warmup'],
        ];

        $first = $this->withToken('test-api-key')
            ->postJson('/v0/inboxes', $payload)
            ->assertOk()
            ->json('inbox_id');

        $this->withToken('test-api-key')
            ->postJson('/v0/inboxes', $payload)
            ->assertOk()
            ->assertJsonPath('inbox_id', $first)
            ->assertJsonPath('display_name', 'SucceedReach Warmer');

        $this->assertSame(1, Inbox::query()->count());
    }

    public function test_send_with_reply_headers_keeps_the_thread(): void
    {
        $inbox = Inbox::factory()->create([
            'address' => 'warmer@oneloqin.xyz',
            'local_part' => 'warmer',
        ]);

        Message::query()->create([
            'inbox_id' => $inbox->id,
            'direction' => 'inbound',
            'from_address' => 'ada@example.com',
            'to_address' => 'warmer@oneloqin.xyz',
            'subject' => 'Question',
            'body_text' => 'Hello',
            'thread_id' => '<thread@oneloqin.xyz>',
            'message_id' => '<original@oneloqin.xyz>',
            'is_read' => false,
            'status' => 'received',
        ]);

        $this->withToken('test-api-key')
            ->postJson('/v0/inboxes/'.rawurlencode('warmer@oneloqin.xyz').'/messages/send', [
                'to' => ['ada@example.com'],
                'subject' => 'Re: Question',
                'text' => 'Thanks',
                'headers' => [
                    'In-Reply-To' => '<original@oneloqin.xyz>',
                    'References' => '<original@oneloqin.xyz>',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('thread_id', '<thread@oneloqin.xyz>');
    }
}
