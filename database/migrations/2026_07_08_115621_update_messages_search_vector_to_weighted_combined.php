<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Combines a literal ('simple' config, weight A) and stemmed ('english'
     * config, weight B) representation of the body into a single vector, so
     * one search covers both without a user-facing mode toggle. `ts_rank`
     * weights 'A' lexemes higher than 'B', so an exact word match always
     * outranks a match that only exists because of English over-stemming
     * (e.g. "universe" and "university" both stem to 'univers').
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION messages_search_vector_update() RETURNS trigger AS $$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('simple', COALESCE(NEW.body, '')), 'A')
                    || setweight(to_tsvector('english', COALESCE(NEW.body, '')), 'B');
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement(<<<'SQL'
            UPDATE messages SET search_vector =
                setweight(to_tsvector('simple', COALESCE(body, '')), 'A')
                || setweight(to_tsvector('english', COALESCE(body, '')), 'B')
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION messages_search_vector_update() RETURNS trigger AS $$
            BEGIN
                NEW.search_vector := to_tsvector('english', COALESCE(NEW.body, ''));
                RETURN NEW;
            END
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement("UPDATE messages SET search_vector = to_tsvector('english', COALESCE(body, ''))");
    }
};
