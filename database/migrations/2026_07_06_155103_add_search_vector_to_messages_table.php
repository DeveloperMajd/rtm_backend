<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->tsvector('search_vector')->nullable();
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION messages_search_vector_update() RETURNS trigger AS $$
            BEGIN
                NEW.search_vector := to_tsvector('english', COALESCE(NEW.body, ''));
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER messages_search_vector_trigger
            BEFORE INSERT OR UPDATE OF body ON messages
            FOR EACH ROW EXECUTE FUNCTION messages_search_vector_update();
        SQL);

        DB::statement("UPDATE messages SET search_vector = to_tsvector('english', COALESCE(body, ''))");

        Schema::table('messages', function (Blueprint $table) {
            $table->index('search_vector', 'messages_search_vector_index', 'gin');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS messages_search_vector_trigger ON messages');
        DB::statement('DROP FUNCTION IF EXISTS messages_search_vector_update');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_search_vector_index');
            $table->dropColumn('search_vector');
        });
    }
};
