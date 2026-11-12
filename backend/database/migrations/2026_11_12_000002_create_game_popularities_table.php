<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Standard SQL keeps the same CHECK constraint in PostgreSQL and SQLite tests.
        DB::statement(<<<'SQL'
            CREATE TABLE game_popularities (
                game_id BIGINT NOT NULL PRIMARY KEY REFERENCES games(id) ON DELETE CASCADE,
                purchase_count BIGINT NOT NULL DEFAULT 0,
                CONSTRAINT game_popularities_purchase_count_nonnegative CHECK (purchase_count >= 0)
            )
            SQL);

        DB::statement('CREATE INDEX idx_game_popularity_ranking ON game_popularities (purchase_count DESC, game_id ASC)');

        // Existing rows are never overwritten; this backfill also works on an empty DB.
        DB::statement(<<<'SQL'
            INSERT INTO game_popularities (game_id, purchase_count)
            SELECT id, 0 FROM games WHERE 1 = 1
            ON CONFLICT (game_id) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('game_popularities');
    }
};
