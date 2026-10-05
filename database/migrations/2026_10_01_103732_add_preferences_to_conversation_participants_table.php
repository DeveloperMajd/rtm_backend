<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pin, mute and archive are each person's own view of a conversation,
     * so they live on that person's participant row, not on the
     * conversation. Each is a timestamp (null = off) rather than a flag:
     * when it was switched on costs nothing to keep and is there if a
     * "muted until" or pin ordering ever needs it.
     *
     * Nullable with no default, so adding them is a catalogue change in
     * Postgres: no table rewrite and no lock held while the app runs.
     */
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('muted_at')->nullable();
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropColumn(['pinned_at', 'muted_at', 'archived_at']);
        });
    }
};
