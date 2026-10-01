<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each person's notification and privacy settings, one row each, keyed
     * by the user. A row is only written the first time someone changes a
     * setting: until then they have the defaults (UserSettings::DEFAULTS),
     * so nobody needs one made for them now.
     *
     * The defaults are what the app has always done: read state, last seen
     * and typing are all shared (read state since read receipts), and
     * nothing makes a sound or shows an alert.
     */
    public function up(): void
    {
        Schema::create('user_settings', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('read_receipts')->default(true);
            $table->string('last_seen_visibility', 16)->default('everyone');
            $table->boolean('typing_indicators')->default(true);
            $table->boolean('message_sounds')->default(false);
            $table->boolean('desktop_notifications')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
    }
};
