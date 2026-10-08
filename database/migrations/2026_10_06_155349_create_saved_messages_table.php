<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The messages each person has saved (Saved messages), one row per
     * person and message. A new table, so nothing already running reads or
     * writes it.
     *
     * The id is a UUIDv7, so it orders the rows by when they were saved:
     * the list reads (user_id, id) newest first, which Postgres does by
     * walking that index backwards. A row goes with its message or its
     * person. One kept for a conversation the person has since left stays
     * hidden, and shows again if they're added back.
     */
    public function up(): void
    {
        Schema::create('saved_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('message_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['user_id', 'message_id']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_messages');
    }
};
