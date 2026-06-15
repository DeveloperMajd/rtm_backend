<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION uuid_larger(uuid, uuid) RETURNS uuid
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
                SELECT CASE WHEN $1 > $2 THEN $1 ELSE $2 END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE AGGREGATE max(uuid) (
                sfunc    = uuid_larger,
                stype    = uuid,
                combinefunc = uuid_larger,
                sortop   = >
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION uuid_smaller(uuid, uuid) RETURNS uuid
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$
                SELECT CASE WHEN $1 < $2 THEN $1 ELSE $2 END;
            $$
        SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE AGGREGATE min(uuid) (
                sfunc    = uuid_smaller,
                stype    = uuid,
                combinefunc = uuid_smaller,
                sortop   = <
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP AGGREGATE IF EXISTS max(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS uuid_larger(uuid, uuid)');
        DB::statement('DROP AGGREGATE IF EXISTS min(uuid)');
        DB::statement('DROP FUNCTION IF EXISTS uuid_smaller(uuid, uuid)');
    }
};
