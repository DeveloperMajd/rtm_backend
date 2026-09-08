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
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Nullable so a file can be uploaded and previewed before its
            // message is sent; the message send links it by setting this.
            $table->foreignUuid('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('uploaded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index('message_id');
        });

        // Denormalized for list-view / conversation-media use without loading
        // attachment rows; populated on message send (same treatment as
        // conversations.last_message_id in V5g, not a decorative column).
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedInteger('attachments_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('attachments_count');
        });

        Schema::dropIfExists('attachments');
    }
};
