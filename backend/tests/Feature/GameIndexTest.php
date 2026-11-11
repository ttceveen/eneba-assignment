<?php

namespace Tests\Feature;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_get_all_games_with_only_the_public_fields(): void
    {
        $games = [
            Game::create(['name' => 'First game', 'location' => ['GLOBAL'], 'img' => null]),
            Game::create(['name' => 'Second game', 'location' => ['LT', 'PL', 'DE'], 'img' => 'game.jpg']),
            Game::create(['name' => 'First game', 'location' => ['PL'], 'img' => null]),
        ];

        $this->assertGuest();

        $response = $this->getJson('/api/games');
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonCount(count($games), 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'location', 'img']]]);

        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertEqualsCanonicalizing(
            array_map(fn (Game $game) => [
                'id' => $game->id,
                'name' => $game->name,
                'location' => $game->location,
                'img' => $game->img,
            ], $games),
            $response->json('data'),
        );

        foreach ($response->json('data') as $game) {
            $this->assertIsArray($game['location']);
            $this->assertArrayNotHasKey('created_at', $game);
            $this->assertArrayNotHasKey('updated_at', $game);
            $this->assertCount(4, $game);
        }
    }

    public function test_guest_gets_an_empty_data_array_when_there_are_no_games(): void
    {
        $this->assertGuest();

        $this->getJson('/api/games')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
}
