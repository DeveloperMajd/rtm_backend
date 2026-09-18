<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Delete group" — admin-only, whole-conversation, visible to no one
     * afterward. A manual nullable timestamp, not Eloquent's SoftDeletes
     * trait, matching this project's existing tombstone-column pattern
     * (messages.deleted_at, conversation_participants.left_at) rather than
     * introducing a second soft-delete mechanism.
     */
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->after('metadata');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('deleted_at');
        });
    }
};
