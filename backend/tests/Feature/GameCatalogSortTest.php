<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameCatalogSortTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_sorts_by_the_minimum_available_matching_offer(): void
    {
        $first = $this->game('Zeta');
        $second = $this->game('Alpha');
        $third = $this->game('Bravo', ['PL']);
        $unpriced = $this->game('No available offers');
        $seller = Seller::create(['name' => 'Seller']);
        $other = Seller::create(['name' => 'Other seller']);
        $inactive = Seller::create(['name' => 'Inactive seller', 'is_active' => false]);

        $this->offer($first, $seller, 'Xbox', 5);
        $this->offer($first, $other, 'Steam', 30);
        $this->offer($first, $inactive, 'Steam', 1);
        $this->offer($second, $seller, 'Steam', 20);
        $this->offer($second, $other, 'Nintendo', 50);
        $this->offer($third, $seller, 'Nintendo', 25);
        $this->offer($third, $other, 'Steam', 2, 0);
        $this->offer($unpriced, $seller, 'Steam', 1, 0);

        foreach ([
            ['sort=price_asc', [$first->id, $second->id, $third->id, $unpriced->id], ['5.00', '20.00', '25.00', null]],
            ['sort=price_desc', [$third->id, $second->id, $first->id, $unpriced->id], ['25.00', '20.00', '5.00', null]],
            ['platform=Steam&sort=price_asc', [$second->id, $first->id], ['20.00', '30.00']],
            ['platform=Nintendo&sort=price_desc', [$second->id, $third->id], ['50.00', '25.00']],
            ['min_price=10&max_price=50&sort=price_asc', [$second->id, $third->id, $first->id], ['20.00', '25.00', '30.00']],
            ['platform=Steam&region=Global&min_price=10&max_price=50&sort=price_desc', [$first->id, $second->id], ['30.00', '20.00']],
        ] as [$query, $ids, $prices]) {
            $rows = $this->getJson('/api/games?'.$query)->assertOk()->json('data');
            $this->assertSame($ids, array_column($rows, 'id'), $query);
            $this->assertSame($prices, array_column($rows, 'min_price'), $query);
        }
    }

    public function test_name_newest_and_default_have_stable_game_id_ties(): void
    {
        $zeta = $this->game('zeta', ['DE']);
        $first = $this->game('Alpha', ['LT']);
        $second = $this->game('alpha', ['PL']);
        $seller = Seller::create(['name' => 'Seller']);
        foreach ([$zeta, $first, $second] as $game) {
            $this->offer($game, $seller, 'Steam', 20);
        }
        $zeta->forceFill(['created_at' => '2020-01-01'])->save();
        $first->forceFill(['created_at' => '2021-01-01'])->save();
        $second->forceFill(['created_at' => '2021-01-01'])->save();
        $second->popularity()->update(['purchase_count' => 7]);

        foreach ([
            'region=Europe&sort=name_asc' => [$first->id, $second->id, $zeta->id],
            'sort=name_desc' => [$zeta->id, $first->id, $second->id],
            'sort=newest' => [$first->id, $second->id, $zeta->id],
            '' => [$second->id, $zeta->id, $first->id],
        ] as $query => $expected) {
            $this->assertSame($expected, array_column($this->getJson('/api/games?'.$query)->assertOk()->json('data'), 'id'));
        }
    }

    public function test_price_order_runs_before_pagination_and_breaks_price_ties_by_id(): void
    {
        $seller = Seller::create(['name' => 'Seller']);
        $prices = [];
        for ($i = 0; $i < 28; $i++) {
            $game = $this->game('Game '.$i);
            $price = $i % 2 === 0 ? 25 : 10;
            $this->offer($game, $seller, 'Steam', $price);
            $prices[$game->id] = $price;
        }
        asort($prices);
        $expected = array_keys($prices);
        $seen = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->getJson('/api/games?platform=Steam&sort=price_asc&page='.$page)->assertOk()
                ->assertJsonPath('meta.total', 28)->assertJsonPath('meta.per_page', 12)->assertJsonPath('meta.current_page', $page);
            $ids = array_column($response->json('data'), 'id');
            $this->assertSame(array_slice($expected, ($page - 1) * 12, 12), $ids);
            $seen = array_merge($seen, $ids);
            $this->assertStringContainsString('platform=Steam&sort=price_asc', $response->json('links.first'));
        }
        $this->assertSame($expected, $seen);
    }

    public function test_invalid_sort_is_a_validation_error_for_catalog_and_search(): void
    {
        foreach (['/api/games?', '/api/games/search?q=portal&'] as $url) {
            foreach (['sort=unknown', 'sort=', 'sort[]=price_asc'] as $query) {
                $this->getJson($url.$query)->assertUnprocessable()->assertJsonValidationErrors('sort');
            }
        }
    }

    private function game(string $name, array $location = ['GLOBAL']): Game
    {
        return Game::create(['name' => $name, 'location' => $location]);
    }

    private function offer(Game $game, Seller $seller, string $platform, int $price, int $quantity = 1): void
    {
        $game->offers()->create([
            'seller_id' => $seller->id, 'platform' => $platform, 'price' => $price, 'quantity' => $quantity,
        ]);
    }
}
