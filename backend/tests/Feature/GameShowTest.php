<?php

namespace Tests\Feature;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_read_the_requested_game_with_only_public_fields(): void
    {
        $games = [
            Game::create(['name' => 'First game', 'location' => ['GLOBAL'], 'img' => null]),
            Game::create(['name' => 'Second game', 'location' => ['LT', 'PL', 'DE'], 'img' => 'game.jpg']),
        ];

        $this->assertGuest();

        foreach ($games as $game) {
            $response = $this->getJson('/api/games/'.$game->id);
            $response->assertOk()->assertExactJson(['data' => [
                'id' => $game->id,
                'name' => $game->name,
                'location' => $game->location,
                'img' => $game->img,
                'min_price' => null,
                'offers_count' => 0,
                'offers' => [],
            ]]);
            $this->assertIsArray($response->json('data.location'));
            $response->assertJsonMissingPath('data.created_at')->assertJsonMissingPath('data.updated_at');
        }
    }

    public function test_unknown_or_non_numeric_game_ids_return_not_found(): void
    {
        $this->assertGuest();
        $this->getJson('/api/games/999999')->assertNotFound();
        $this->getJson('/api/games/not-a-game')->assertNotFound();
    }
}
