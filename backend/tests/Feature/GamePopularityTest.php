<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GamePopularity;
use Database\Seeders\GamePopularitySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GamePopularityTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_game_is_active_and_has_exactly_one_zero_popularity(): void
    {
        $game = Game::create(['name' => 'New game', 'location' => ['GLOBAL']]);

        $this->assertTrue($game->is_active);
        $this->assertTrue($game->fresh()->is_active);
        $this->assertSame(0, $game->popularity->purchase_count);
        $this->assertSame($game->id, $game->popularity->game->id);
        $this->assertDatabaseCount('game_popularities', 1);
        $this->assertFalse($game->popularity->incrementing);
        $this->assertNotNull($game->popularity->created_at);
        $this->assertSame('game_id', $game->popularity->getKeyName());
    }

    public function test_popularity_primary_key_prevents_duplicates(): void
    {
        $game = Game::create(['name' => 'Unique popularity', 'location' => ['GLOBAL']]);
        $this->expectException(QueryException::class);
        GamePopularity::create(['game_id' => $game->id, 'purchase_count' => 0]);
    }

    public function test_popularity_cannot_be_negative(): void
    {
        $game = Game::create(['name' => 'Nonnegative', 'location' => ['GLOBAL']]);
        $this->expectException(QueryException::class);
        $game->popularity->update(['purchase_count' => -1]);
    }

    public function test_popularity_cannot_be_null(): void
    {
        $game = Game::create(['name' => 'Not null', 'location' => ['GLOBAL']]);
        $this->expectException(QueryException::class);
        $game->popularity->update(['purchase_count' => null]);
    }

    public function test_popularity_requires_an_existing_game(): void
    {
        $this->expectException(QueryException::class);
        GamePopularity::create(['game_id' => 999999, 'purchase_count' => 0]);
    }

    public function test_deleting_game_cascades_to_popularity(): void
    {
        $game = Game::create(['name' => 'Delete test', 'location' => ['GLOBAL']]);
        $id = $game->id;
        $game->delete();

        $this->assertDatabaseMissing('games', ['id' => $id]);
        $this->assertDatabaseMissing('game_popularities', ['game_id' => $id]);
    }

    public function test_game_updates_do_not_reset_popularity_and_popularity_does_not_touch_game(): void
    {
        $game = Game::create(['name' => 'Independent', 'location' => ['GLOBAL']]);
        $before = $game->fresh()->getRawOriginal();
        $game->popularity->update(['purchase_count' => 99]);
        $this->assertSame($before, $game->fresh()->getRawOriginal());

        $game->update(['is_active' => false]);
        $this->assertFalse($game->fresh()->is_active);
        $this->assertSame(99, $game->popularity()->first()->purchase_count);
        $game->update(['is_active' => true, 'name' => 'Renamed']);
        $this->assertSame(99, $game->popularity()->first()->purchase_count);
        $this->assertDatabaseCount('game_popularities', 1);
    }

    public function test_new_game_rolls_back_if_its_popularity_cannot_be_created(): void
    {
        GamePopularity::creating(function (): void {
            throw new \RuntimeException('Simulated popularity failure');
        });

        try {
            try {
                Game::create(['name' => 'Atomic insert', 'location' => ['GLOBAL']]);
                $this->fail('Expected popularity creation to fail');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Simulated popularity failure', $exception->getMessage());
            }
            $this->assertDatabaseMissing('games', ['name' => 'Atomic insert']);
            $this->assertDatabaseCount('game_popularities', 0);
        } finally {
            GamePopularity::flushEventListeners();
        }
    }

    public function test_demo_seed_is_reproducible_and_does_not_overwrite_later_sales(): void
    {
        $this->seed(GameSeeder::class);
        $gameIds = Game::orderBy('id')->pluck('id');
        $this->assertCount(40, $gameIds);
        $snapshot = fn () => GamePopularity::orderBy('game_id')->pluck('purchase_count', 'game_id')->all();

        DB::beginTransaction();
        try {
            $this->seed(GamePopularitySeeder::class);
            $first = $snapshot();
        } finally {
            DB::rollBack();
        }

        $this->seed(GamePopularitySeeder::class);
        $this->assertSame($first, $snapshot());
        $positive = array_filter($first);
        $this->assertCount(12, $positive);
        $this->assertCount(28, array_filter($first, fn (int $count) => $count === 0));
        $this->assertGreaterThanOrEqual(1, min($positive));
        $this->assertLessThanOrEqual(1000, max($positive));
        $this->assertGreaterThan(1, count(array_unique($positive)));

        GamePopularity::recordSale(array_key_first($positive), 2);
        $afterSale = $snapshot();
        $this->seed(GamePopularitySeeder::class);
        $this->assertSame($afterSale, $snapshot());
        $this->assertSame($gameIds->all(), Game::orderBy('id')->pluck('id')->all());
        $this->assertDatabaseCount('game_popularities', 40);

        GamePopularity::query()->update(['purchase_count' => 0]);
        $this->seed(GamePopularitySeeder::class);
        $this->assertSame(0, GamePopularity::sum('purchase_count'));
    }

    public function test_demo_seed_rounds_up_and_refuses_preexisting_activity(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Game::create(['name' => 'Demo '.$i, 'location' => ['GLOBAL']]);
        }
        $popularity = GamePopularity::firstOrFail();
        $popularity->update(['purchase_count' => 1]);
        $this->seed(GamePopularitySeeder::class);
        $this->assertSame(1, GamePopularity::sum('purchase_count'));

        $popularity->update(['purchase_count' => 0]);
        $this->seed(GamePopularitySeeder::class);
        $this->assertSame(2, GamePopularity::where('purchase_count', '>', 0)->count());
    }
}
