<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Group event lines ("X added Y", "X left", ...) are stored as messages
     * with type='system' so they flow through the same pagination, broadcast
     * and rendering pipeline as normal messages. They have no human sender
     * (hence sender_user_id becoming nullable), an empty body, and carry
     * their display data in event_type + metadata — resolved to copy on the
     * frontend so the strings live in one place.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->string('type')->default('user')->after('conversation_id');
            $table->string('event_type')->nullable()->after('type');
            $table->jsonb('metadata')->nullable()->after('event_type');
        });

        DB::statement('ALTER TABLE messages ALTER COLUMN sender_user_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages ALTER COLUMN sender_user_id SET NOT NULL');

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn(['type', 'event_type', 'metadata']);
        });
    }
};
