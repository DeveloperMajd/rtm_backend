<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->foreignUuid('last_read_message_id')->nullable()->after('role')->constrained('messages')->nullOnDelete();
            $table->timestamp('last_read_at')->nullable()->after('last_read_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_participants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_read_message_id');
            $table->dropColumn('last_read_at');
        });
    }
};
