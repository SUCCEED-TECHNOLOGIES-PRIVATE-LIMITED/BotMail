<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inboxes', function (Blueprint $table) {
            $table->string('client_id')->nullable()->unique()->after('display_name');
            $table->json('metadata')->nullable()->after('client_id');
        });
    }

    public function down(): void
    {
        Schema::table('inboxes', function (Blueprint $table) {
            $table->dropUnique(['client_id']);
            $table->dropColumn(['client_id', 'metadata']);
        });
    }
};
