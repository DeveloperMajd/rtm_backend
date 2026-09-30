<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY refuses to run inside a transaction block,
     * and Laravel wraps each Postgres migration in one unless told not to.
     */
    public $withinTransaction = false;

    /**
     * Two lookups every conversation screen makes had no index behind them:
     *
     * - messages(conversation_id, id): every page of history, and every
     *   jump to a message, reads one conversation's messages in id order
     *   from a cursor. Without it Postgres filters the whole table.
     * - conversation_participants(user_id): "which conversations am I in"
     *   (the conversation list, search, and the visibility rule on every
     *   message query). The existing unique index leads with
     *   conversation_id, so it can't serve a lookup by user alone.
     *
     * CONCURRENTLY so the deploy doesn't lock either table for writes while
     * the index builds: the live app keeps sending messages throughout.
     *
     * Each index is dropped first. This migration is only ever re-run after
     * an attempt that failed, and a concurrent build that fails leaves an
     * INVALID index behind under the same name, which IF NOT EXISTS alone
     * would mistake for a finished one.
     */
    public function up(): void
    {
        foreach ([
            'messages_conversation_id_id_index' => 'messages (conversation_id, id)',
            'conversation_participants_user_id_index' => 'conversation_participants (user_id)',
        ] as $name => $definition) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            DB::statement("CREATE INDEX CONCURRENTLY {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS messages_conversation_id_id_index');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS conversation_participants_user_id_index');
    }
};
