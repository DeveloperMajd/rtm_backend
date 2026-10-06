<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which parts of each person's reading they shared: the stretches their
     * read pointer covered while they had read receipts on. A list of
     * [from, to] message ids on that person's participant row (from is
     * exclusive, null for the very beginning; to is inclusive, null while the
     * stretch is still open). See ReadReceiptVisibility.
     *
     * Null until the person next switches read receipts on or off: a row
     * without a list follows their current setting for all of its history,
     * which is exactly what everyone is shown today. So nothing is
     * backfilled, and nobody's screen changes when this ships.
     *
     * Nullable with no default, so adding it is a catalogue change in
     * Postgres: no table rewrite and no lock held while the app runs.
     */
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->jsonb('receipt_stretches')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropColumn('receipt_stretches');
        });
    }
};
