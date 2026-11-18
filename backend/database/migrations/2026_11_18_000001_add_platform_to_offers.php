<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('offers', fn (Blueprint $table) => $table->enum('platform', ['Steam', 'Xbox', 'PlayStation', 'Nintendo']));

            return;
        }

        DB::statement("CREATE TYPE offer_platform AS ENUM ('Steam', 'Xbox', 'PlayStation', 'Nintendo')");
        DB::statement('ALTER TABLE offers ADD COLUMN platform offer_platform');

        // Demo data: every game omits one platform. Shuffle the games and
        // cycle the omissions and first assignments to cover all platforms.
        DB::statement(<<<'SQL'
            WITH games AS (
                SELECT game_id, (row_number() OVER (ORDER BY random()) - 1) % 4 AS omitted
                FROM (SELECT DISTINCT game_id FROM offers) AS offered_games
            ), ranked_offers AS (
                SELECT id, games.omitted, row_number() OVER (PARTITION BY offers.game_id ORDER BY random()) AS position
                FROM offers JOIN games USING (game_id)
            )
            UPDATE offers
            SET platform = (enum_range(NULL::offer_platform))[
                1 + (omitted + CASE WHEN position = 1 THEN 1 ELSE 1 + floor(random() * 3)::int END) % 4
            ]
            FROM ranked_offers
            WHERE offers.id = ranked_offers.id
            SQL);

        DB::statement('ALTER TABLE offers ALTER COLUMN platform SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $table) => $table->dropColumn('platform'));

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TYPE offer_platform');
        }
    }
};
