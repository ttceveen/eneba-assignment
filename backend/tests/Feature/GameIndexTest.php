<?php

namespace Tests\Feature;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_get_games_with_only_the_public_fields(): void
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

        $response->assertJsonStructure([
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ])->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 12)
            ->assertJsonPath('meta.total', 3);
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
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.per_page', 12)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('links.prev', null)
            ->assertJsonPath('links.next', null);
    }

    public function test_pages_are_ordered_disjoint_and_include_standard_pagination(): void
    {
        $ids = [];
        for ($i = 0; $i < 40; $i++) {
            $ids[] = Game::create(['name' => 'Game '.$i, 'location' => ['GLOBAL'], 'img' => null])->id;
        }

        $seen = [];
        for ($page = 1; $page <= 5; $page++) {
            $response = $this->getJson('/api/games?page='.$page);
            $response->assertOk()
                ->assertJsonPath('meta.current_page', $page)
                ->assertJsonPath('meta.last_page', 4)
                ->assertJsonPath('meta.per_page', 12)
                ->assertJsonPath('meta.total', 40)
                ->assertJsonCount(count(array_slice($ids, ($page - 1) * 12, 12)), 'data');

            $actual = array_column($response->json('data'), 'id');
            $this->assertSame(array_slice($ids, ($page - 1) * 12, 12), $actual);
            $this->assertEmpty(array_intersect($seen, $actual));
            $seen = array_merge($seen, $actual);
            $this->assertStringEndsWith('/api/games?page=1', $response->json('links.first'));
            $this->assertStringEndsWith('/api/games?page=4', $response->json('links.last'));
            if ($page === 1) {
                $response->assertJsonPath('links.prev', null);
            } else {
                $this->assertStringEndsWith('/api/games?page='.($page - 1), $response->json('links.prev'));
            }
            if ($page >= 4) {
                $response->assertJsonPath('links.next', null);
            } else {
                $this->assertStringEndsWith('/api/games?page='.($page + 1), $response->json('links.next'));
            }
        }

        $this->assertSame($ids, $seen);
        $default = $this->getJson('/api/games')->assertOk()->json();
        $first = $this->getJson('/api/games?page=1')->assertOk()->json();
        $this->assertSame($first, $default);
        $this->getJson('/api/games?per_page=100')->assertOk()
            ->assertJsonCount(12, 'data')->assertJsonPath('meta.per_page', 12);
    }
}
