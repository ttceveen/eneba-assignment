<?php

namespace Tests\Feature;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranking_and_pagination_include_only_active_games(): void
    {
        $games = [];
        for ($i = 0; $i < 40; $i++) {
            $games[] = Game::create(['name' => 'Game '.$i, 'location' => ['GLOBAL'], 'is_active' => $i < 31]);
        }
        foreach ([0 => 10, 1 => 30, 2 => 20, 3 => 30, 31 => 999] as $index => $count) {
            $games[$index]->popularity->update(['purchase_count' => $count]);
        }
        $expected = [$games[1]->id, $games[3]->id, $games[2]->id, $games[0]->id];
        for ($i = 4; $i < 31; $i++) {
            $expected[] = $games[$i]->id;
        }

        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            $response = $this->getJson('/api/games?page='.$page)->assertOk()
                ->assertJsonPath('meta.total', 31)->assertJsonPath('meta.last_page', 3)
                ->assertJsonPath('meta.per_page', 12)->assertJsonPath('meta.current_page', $page)
                ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);
            $ids = array_column($response->json('data'), 'id');
            $this->assertSame(array_slice($expected, ($page - 1) * 12, 12), $ids);
            $this->assertEmpty(array_intersect($seen, $ids));
            $seen = array_merge($seen, $ids);
            foreach ($response->json('data') as $game) {
                $this->assertCount(6, $game);
                $this->assertArrayNotHasKey('purchase_count', $game);
                $this->assertArrayNotHasKey('is_active', $game);
            }
        }
        $this->assertSame($expected, $seen);
    }

    public function test_inactive_game_is_not_available_through_public_details(): void
    {
        $game = Game::create(['name' => 'Private inactive game', 'location' => ['GLOBAL'], 'is_active' => false]);

        $this->assertGuest();
        $this->getJson('/api/games/'.$game->id)->assertNotFound()->assertJsonMissingPath('data');
        $this->getJson('/api/games')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }
}
