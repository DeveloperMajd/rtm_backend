<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->jsonb('metadata')->nullable();
            $table->foreignUuid('last_message_id')->nullable()->constrained('messages')->nullOnDelete();
        });

        // Backfill existing conversations so list views don't go blank until
        // their next message. UUIDv7 ids sort chronologically, so the
        // highest id per conversation is also its most recent message.
        DB::statement(<<<'SQL'
            UPDATE conversations c
            SET last_message_id = m.id
            FROM (
                SELECT DISTINCT ON (conversation_id) conversation_id, id
                FROM messages
                ORDER BY conversation_id, id DESC
            ) m
            WHERE c.id = m.conversation_id
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_message_id');
            $table->dropColumn('metadata');
        });
    }
};
