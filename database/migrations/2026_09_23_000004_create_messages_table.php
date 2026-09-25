<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbox_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('from_address');
            $table->text('to_address');
            $table->text('cc')->nullable();
            $table->text('bcc')->nullable();
            $table->string('subject', 998);
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->json('headers')->nullable();
            $table->json('attachments')->nullable();
            $table->string('thread_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->text('references')->nullable();
            $table->boolean('is_read')->default(false);
            $table->string('resend_id')->nullable();
            $table->string('status');
            $table->string('message_id')->nullable();
            $table->timestamps();

            $table->index(['inbox_id', 'direction']);
            $table->index('thread_id');
            $table->index('created_at');
            $table->index('is_read');
            $table->index('resend_id');
            $table->index(['direction', 'status', 'created_at']);
            $table->unique(['inbox_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
