<?php

namespace Tests\Feature;

use App\Models\Inbox;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_panel_pages_render(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->get('/admin')->assertOk();
        $this->get('/admin/inboxes')->assertOk();
        $this->get('/admin/messages')->assertOk();
        $this->get('/admin/domains')->assertOk();
        $this->get('/admin/compose')->assertOk();
        $this->get('/admin/settings')->assertOk();

        $inbox = Inbox::factory()->create();
        $message = Message::query()->create([
            'inbox_id' => $inbox->id,
            'direction' => 'inbound',
            'from_address' => 'ada@example.com',
            'to_address' => $inbox->address,
            'subject' => 'Hello',
            'body_html' => '<p>Hi</p><script>alert(1)</script>',
            'body_text' => 'Hi',
            'thread_id' => '<hello@example.com>',
            'message_id' => '<hello@example.com>',
            'is_read' => false,
            'status' => 'received',
            'attachments' => [[
                'filename' => 'note.txt',
                'mime' => 'text/plain',
                'size' => 2,
                'data' => base64_encode('hi'),
            ]],
        ]);

        $this->get('/admin/messages/'.$message->id)
            ->assertOk()
            ->assertSee('Hello')
            ->assertSee('note.txt')
            ->assertDontSee('alert(1)', false);

        auth()->logout();

        $this->get('/admin/login')->assertOk();
    }
}
