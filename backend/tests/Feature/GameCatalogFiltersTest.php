<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameCatalogFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_use_one_available_offer_and_keep_matching_offer_summaries(): void
    {
        $global = $this->game('Global game', ['GLOBAL']);
        $europe = $this->game('European game', ['PL']);
        $unavailable = $this->game('Unavailable game', ['GLOBAL']);
        $this->offer($global, 'Steam', 10);
        $this->offer($global, 'Xbox', 20);
        $this->offer($europe, 'Steam', 20);
        $this->offer($global, 'Steam', 20, ['quantity' => 0]);
        $this->offer($global, 'Steam', 20, ['is_active' => false]);
        $this->offer($global, 'Steam', 20, sellerActive: false);
        $this->offer($unavailable, 'Steam', 20, ['quantity' => 0]);

        foreach ([
            ['platform=Steam', [$global->id, $europe->id]],
            ['platform=Xbox', [$global->id]],
            ['region=Europe', [$europe->id]],
            ['region=Global', [$global->id]],
            ['region=North+America', []],
            ['min_price=20', [$global->id, $europe->id]],
            ['max_price=10', [$global->id]],
            ['min_price=20&max_price=20', [$global->id, $europe->id]],
            ['platform=Steam&region=Global', [$global->id]],
            ['platform=Steam&region=Europe&min_price=20&max_price=20', [$europe->id]],
            ['platform=Steam&region=Global&min_price=20&max_price=20', []],
            ['platform=Nintendo', []],
        ] as [$query, $expected]) {
            $response = $this->getJson('/api/games?'.$query)->assertOk()->assertJsonPath('meta.total', count($expected));
            $this->assertSame($expected, array_column($response->json('data'), 'id'), $query);
        }

        $this->getJson('/api/games?platform=Xbox&min_price=20')
            ->assertJsonPath('data.0.min_price', '20.00')
            ->assertJsonPath('data.0.offers_count', 1);
        $this->getJson('/api/games')->assertJsonPath('meta.total', 3);
    }

    public function test_catalog_and_search_validate_filter_parameters(): void
    {
        foreach (['/api/games?', '/api/games/search?q=portal&'] as $endpoint) {
            foreach ([
                'platform=Switch' => 'platform',
                'platform[]=Steam' => 'platform',
                'region=Asia' => 'region',
                'min_price=-1' => 'min_price',
                'max_price=-1' => 'max_price',
                'max_price=invalid' => 'max_price',
                'min_price=' => 'min_price',
                'min_price=21&max_price=20' => 'max_price',
            ] as $query => $field) {
                $this->getJson($endpoint.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
            }
        }
    }

    private function game(string $name, array $location): Game
    {
        return Game::create(['name' => $name, 'location' => $location]);
    }

    private function offer(Game $game, string $platform, int $price, array $attributes = [], bool $sellerActive = true): void
    {
        $seller = Seller::create(['name' => 'Seller '.(Seller::count() + 1), 'is_active' => $sellerActive]);
        $game->offers()->create($attributes + ['seller_id' => $seller->id, 'platform' => $platform, 'price' => $price, 'quantity' => 1]);
    }
}
