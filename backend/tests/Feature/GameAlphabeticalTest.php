<?php

namespace Tests\Feature;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameAlphabeticalTest extends TestCase
{
    use RefreshDatabase;

    public function test_alphabetical_is_case_insensitive_with_id_ties_and_keeps_popular_default(): void
    {
        $beta = Game::create(['name' => 'beta', 'location' => ['GLOBAL']]);
        $first = Game::create(['name' => 'Alpha', 'location' => ['GLOBAL']]);
        $second = Game::create(['name' => 'alpha', 'location' => ['GLOBAL']]);
        $third = Game::create(['name' => 'ALPHA', 'location' => ['GLOBAL']]);
        Game::create(['name' => 'A hidden game', 'location' => ['GLOBAL'], 'is_active' => false]);
        $beta->popularity->update(['purchase_count' => 999]);

        $alphabetical = $this->getJson('/api/games?sort=alphabetical')->assertOk()
            ->assertJsonPath('meta.total', 4)->assertJsonPath('meta.per_page', 12);
        $this->assertSame([$first->id, $second->id, $third->id, $beta->id], array_column($alphabetical->json('data'), 'id'));
        $popular = $this->getJson('/api/games')->assertOk();
        $this->assertSame([$beta->id, $first->id, $second->id, $third->id], array_column($popular->json('data'), 'id'));
        $this->assertSame($popular->json('data'), $this->getJson('/api/games?sort=popular')->assertOk()->json('data'));
    }

    public function test_alphabetical_pages_are_disjoint_and_keep_sort_in_links(): void
    {
        $ids = [];
        for ($i = 27; $i >= 0; $i--) {
            $ids[] = Game::create(['name' => sprintf('Game %02d', $i), 'location' => ['GLOBAL']])->id;
        }
        Game::create(['name' => 'A hidden game', 'location' => ['GLOBAL'], 'is_active' => false]);
        $expected = array_reverse($ids);
        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson('/api/games?sort=alphabetical&page='.$page)->assertOk()
                ->assertJsonPath('meta.total', 28)->assertJsonPath('meta.last_page', 3)
                ->assertJsonPath('meta.current_page', $page)->assertJsonPath('meta.per_page', 12);
            $actual = array_column($response->json('data'), 'id');
            $this->assertSame(array_slice($expected, ($page - 1) * 12, 12), $actual);
            $this->assertEmpty(array_intersect($seen, $actual));
            $seen = array_merge($seen, $actual);
            foreach ($response->json('links') as $url) {
                if ($url !== null) {
                    parse_str(parse_url($url, PHP_URL_QUERY), $query);
                    $this->assertSame('alphabetical', $query['sort']);
                    $this->assertArrayHasKey('page', $query);
                }
            }
            foreach ($response->json('data') as $game) {
                $this->assertSame(['id', 'name', 'location', 'img', 'min_price', 'offers_count'], array_keys($game));
            }
        }
        $this->assertSame($expected, $seen);
    }

    public function test_alphabetical_does_not_require_a_popularity_join(): void
    {
        $game = Game::create(['name' => 'Still alphabetical', 'location' => ['GLOBAL']]);
        $game->popularity()->delete();

        $this->getJson('/api/games?sort=alphabetical')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $game->id);
        $this->getJson('/api/games')->assertOk()->assertJsonPath('data', []);
    }
}
