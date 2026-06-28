<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $schema = file_get_contents(database_path('schema.sql'));
        if ($schema === false) {
            throw new RuntimeException('Unable to read database/schema.sql');
        }

        DB::unprepared($schema);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DO $$
DECLARE
    statement text;
BEGIN
    FOR statement IN
        SELECT 'DROP VIEW IF EXISTS ' || quote_ident(schemaname) || '.' || quote_ident(viewname) || ' CASCADE'
        FROM pg_views
        WHERE schemaname = 'public'
    LOOP
        EXECUTE statement;
    END LOOP;

    FOR statement IN
        SELECT 'DROP TABLE IF EXISTS ' || quote_ident(schemaname) || '.' || quote_ident(tablename) || ' CASCADE'
        FROM pg_tables
        WHERE schemaname = 'public'
          AND tablename <> 'migrations'
    LOOP
        EXECUTE statement;
    END LOOP;
END $$;
SQL);
    }
};
