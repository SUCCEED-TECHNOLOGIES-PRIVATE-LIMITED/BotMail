<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Inbox;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_api_key_is_rejected(): void
    {
        $this->getJson('/api/inboxes')->assertUnauthorized();
    }

    public function test_wrong_api_key_is_rejected(): void
    {
        $this->withHeader('X-API-Key', 'nope')
            ->getJson('/api/inboxes')
            ->assertUnauthorized();
    }

    public function test_api_key_lists_and_creates_inboxes(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'example.com',
            'cloudflare_zone_id' => 'zone_123',
        ]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['id' => 'rule_123'],
            ]),
        ]);

        $this->withHeader('X-API-Key', 'test-api-key')
            ->postJson('/api/inboxes', [
                'local_part' => 'agent',
                'domain_id' => $domain->id,
                'display_name' => 'Agent',
            ])
            ->assertCreated()
            ->assertJsonPath('data.address', 'agent@example.com')
            ->assertJsonPath('data.cloudflare_rule_id', 'rule_123');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/email/routing/rules')
                && ($body['actions'][0]['type'] ?? null) === 'worker'
                && ($body['actions'][0]['value'][0] ?? null) === 'botmail-inbound'
                && ($body['matchers'][0]['value'] ?? null) === 'agent@example.com';
        });

        $this->withHeader('X-API-Key', 'test-api-key')
            ->getJson('/api/inboxes')
            ->assertOk()
            ->assertJsonPath('data.0.address', 'agent@example.com');
    }

    public function test_api_sends_replies_and_deletes_messages(): void
    {
        $inbox = Inbox::factory()->create([
            'address' => 'agent@example.com',
            'local_part' => 'agent',
            'cloudflare_rule_id' => 'rule_123',
        ]);

        $original = Message::query()->create([
            'inbox_id' => $inbox->id,
            'direction' => 'inbound',
            'from_address' => 'ada@example.com',
            'to_address' => 'agent@example.com',
            'subject' => 'Question',
            'body_text' => 'Hello',
            'thread_id' => '<question@example.com>',
            'message_id' => '<question@example.com>',
            'is_read' => false,
            'status' => 'received',
        ]);

        $this->withHeader('X-API-Key', 'test-api-key')
            ->postJson("/api/inboxes/{$inbox->id}/messages", [
                'to' => ['bob@example.com'],
                'subject' => 'Hello Bob',
                'text' => 'Hi Bob',
                'attachments' => [
                    [
                        'filename' => 'note.txt',
                        'mime' => 'text/plain',
                        'data' => base64_encode('hello'),
                    ],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.attachments.0.filename', 'note.txt');

        $this->withHeader('X-API-Key', 'test-api-key')
            ->getJson("/api/inboxes/{$inbox->id}/messages")
            ->assertOk()
            ->assertJsonMissingPath('data.0.body_text');

        $this->withHeader('X-API-Key', 'test-api-key')
            ->postJson("/api/inboxes/{$inbox->id}/messages/{$original->id}/reply", [
                'html' => '<p>Thanks</p>',
            ])
            ->assertCreated()
            ->assertJsonPath('data.subject', 'Re: Question')
            ->assertJsonPath('data.in_reply_to', '<question@example.com>')
            ->assertJsonPath('data.thread_id', '<question@example.com>');

        $sent = Message::query()->where('direction', 'outbound')->first();

        $this->withHeader('X-API-Key', 'test-api-key')
            ->deleteJson("/api/inboxes/{$inbox->id}/messages/{$sent->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('messages', ['id' => $sent->id]);
    }

    public function test_api_deletes_an_inbox_and_its_routing_rule(): void
    {
        $user = User::factory()->create();
        $domain = Domain::factory()->create([
            'user_id' => $user->id,
            'cloudflare_zone_id' => 'zone_123',
        ]);
        $inbox = Inbox::factory()->create([
            'user_id' => $user->id,
            'domain_id' => $domain->id,
            'cloudflare_rule_id' => 'rule_123',
        ]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []]),
        ]);

        $this->withHeader('X-API-Key', 'test-api-key')
            ->deleteJson("/api/inboxes/{$inbox->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('inboxes', ['id' => $inbox->id]);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/email/routing/rules/rule_123');
        });
    }
}
