<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The other side of receipt_stretches: what this person could see of
     * each other member's reading. Keyed by that member's user id, each a
     * list of [from, to] positions of their read pointer, covered while this
     * person had read receipts on. A read shows only where the reader's
     * stretches and the viewer's overlap. See ReadReceiptVisibility.
     *
     * Null (or a member missing from it) until the person next switches read
     * receipts: that follows their current setting for all of the history,
     * which is what everyone is shown today, so nothing is backfilled.
     *
     * Nullable with no default, so adding it is a catalogue change in
     * Postgres: no table rewrite and no lock held while the app runs.
     */
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->jsonb('viewer_stretches')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropColumn('viewer_stretches');
        });
    }
};
