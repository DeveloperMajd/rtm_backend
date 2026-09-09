<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `avatar_url` already holds a resolvable URL (a provider URL for OAuth
     * users, or a public disk URL for uploads). `avatar_path` records the
     * storage key of an *uploaded* avatar so the old file can be deleted when
     * it is replaced or removed. It stays null for provider-sourced avatars.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable()->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('avatar_path');
        });
    }
};
