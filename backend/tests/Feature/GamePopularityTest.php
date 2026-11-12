<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GamePopularity;
use Database\Seeders\GamePopularitySeeder;
use Database\Seeders\GameSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertFalse($game->popularity->timestamps);
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

    public function test_seeders_preserve_nonzero_counters_and_do_not_change_games(): void
    {
        $this->seed(GameSeeder::class);
        $this->assertDatabaseCount('games', 40);
        $this->assertDatabaseCount('game_popularities', 40);
        $game = Game::where('name', 'Cyberpunk 2077')->firstOrFail();
        $game->popularity->update(['purchase_count' => 777]);
        $gamesBefore = Game::orderBy('id')->get()->map(fn ($game) => $game->getRawOriginal())->all();

        $this->seed(GamePopularitySeeder::class);
        $this->assertSame(777, $game->popularity()->first()->purchase_count);
        foreach (["The Witcher 3: Wild Hunt" => 320, "Baldur's Gate 3" => 200, 'Grand Theft Auto V' => 110, 'Elden Ring' => 75] as $name => $count) {
            $this->assertSame($count, Game::where('name', $name)->firstOrFail()->popularity->purchase_count);
        }
        $before = GamePopularity::orderBy('game_id')->get()->toArray();
        $this->seed(GamePopularitySeeder::class);
        $this->seed(GameSeeder::class);

        $this->assertSame($before, GamePopularity::orderBy('game_id')->get()->toArray());
        $this->assertSame($gamesBefore, Game::orderBy('id')->get()->map(fn ($game) => $game->getRawOriginal())->all());
        $this->assertDatabaseCount('game_popularities', 40);
    }
}
