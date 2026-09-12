<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `left_at` (timestamp, second precision) is fine for display ("left 3
     * days ago") but too coarse to gate history: two messages created in the
     * same second are indistinguishable by `created_at`. Messages already
     * use UUIDv7 ids for precise chronological comparisons (see read
     * receipts) — this pins the exact last-visible message the same way.
     */
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->foreignUuid('left_at_message_id')
                ->nullable()
                ->after('left_at')
                ->constrained('messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('left_at_message_id');
        });
    }
};
