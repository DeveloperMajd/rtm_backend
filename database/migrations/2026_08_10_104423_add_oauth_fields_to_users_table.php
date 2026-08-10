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
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('avatar_url')->nullable()->after('email_verified_at');
            $table->text('bio')->nullable()->after('avatar_url');
            $table->timestamp('last_seen_at')->nullable()->after('bio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'avatar_url', 'bio', 'last_seen_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
