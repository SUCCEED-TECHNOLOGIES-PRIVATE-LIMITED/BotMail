<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->constrained()->restrictOnDelete();
            $table->string('address')->unique();
            $table->string('local_part');
            $table->string('display_name')->nullable();
            $table->enum('status', ['active', 'paused'])->default('active');
            $table->string('cloudflare_rule_id')->nullable();
            $table->timestamps();

            $table->unique(['domain_id', 'local_part']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inboxes');
    }
};
