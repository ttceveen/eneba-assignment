<?php

namespace Tests\Feature;

use App\Models\DigitalKey;
use App\Models\Game;
use App\Models\Offer;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfferPresentationTest extends TestCase
{
    use RefreshDatabase;

    private function offer(Game $game, string $price, bool $sellerActive = true, bool $active = true, int $quantity = 5): Offer
    {
        return Offer::create([
            'seller_id' => Seller::create(['name' => 'Seller '.$price, 'is_active' => $sellerActive])->id,
            'game_id' => $game->id, 'price' => $price, 'quantity' => $quantity, 'is_active' => $active,
        ]);
    }

    public function test_catalog_and_details_filter_and_order_public_offers_without_keys(): void
    {
        $game = Game::create(['name' => 'Offer fixture', 'location' => ['GLOBAL']]);
        $expensive = $this->offer($game, '20.00');
        $cheap = $this->offer($game, '9.99');
        $this->offer($game, '1.00', sellerActive: false);
        $this->offer($game, '2.00', active: false);
        $this->offer($game, '3.00', quantity: 0);
        DigitalKey::create(['offer_id' => $cheap->id, 'key_value' => 'TEST-FIXTURE']);

        foreach (['popular', 'alphabetical'] as $sort) {
            $payload = $this->getJson('/api/games?sort='.$sort)->assertOk()->json('data.0');
            $this->assertSame('9.99', $payload['min_price']);
            $this->assertSame(2, $payload['offers_count']);
            $this->assertArrayNotHasKey('offers', $payload);
            $this->assertArrayNotHasKey('digital_keys', $payload);
            $this->assertArrayNotHasKey('key_value', $payload);
        }
        $response = $this->getJson('/api/games/'.$game->id)->assertOk()
            ->assertJsonPath('data.min_price', '9.99')->assertJsonPath('data.offers_count', 2);
        $this->assertSame([$cheap->id, $expensive->id], array_column($response->json('data.offers'), 'id'));
        foreach ($response->json('data.offers') as $offer) {
            $this->assertSame(['id', 'seller', 'price', 'quantity'], array_keys($offer));
            $this->assertSame(['id', 'name'], array_keys($offer['seller']));
            $this->assertSame(5, $offer['quantity']);
        }
    }

    public function test_no_available_offers_return_null_minimum_and_zero_count(): void
    {
        $game = Game::create(['name' => 'Unavailable fixture', 'location' => ['GLOBAL']]);
        $this->offer($game, '1.00', sellerActive: false);
        $this->getJson('/api/games')->assertOk()->assertJsonPath('data.0.min_price', null)->assertJsonPath('data.0.offers_count', 0);
        $this->getJson('/api/games/'.$game->id)->assertOk()->assertJsonPath('data.offers', [])->assertJsonPath('data.min_price', null);
    }

    public function test_zero_price_is_a_real_available_price_and_ties_are_stable(): void
    {
        $game = Game::create(['name' => 'Zero fixture', 'location' => ['GLOBAL']]);
        $first = $this->offer($game, '0.00');
        $second = $this->offer($game, '0.00');
        $this->getJson('/api/games')->assertOk()->assertJsonPath('data.0.min_price', '0.00');
        $this->assertSame([$first->id, $second->id], array_column($this->getJson('/api/games/'.$game->id)->json('data.offers'), 'id'));
    }

    public function test_catalog_query_count_is_constant_and_does_not_fetch_stock_or_offers(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $game = Game::create(['name' => 'Query fixture '.$i, 'location' => ['GLOBAL']]);
            $this->offer($game, '12.00');
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->getJson('/api/games')->assertOk()->assertJsonCount(12, 'data');
        $this->assertCount(2, $queries); // COUNT + one page with correlated SQL aggregates.
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('digital_keys', $query);
            $this->assertStringNotContainsString('key_value', $query);
        }
        $this->assertStringContainsString('min(', $queries[1]);
        $this->assertStringContainsString('offers_count', $queries[1]);
    }

    public function test_details_eager_load_sellers_without_reading_digital_keys(): void
    {
        $game = Game::create(['name' => 'Detail query fixture', 'location' => ['GLOBAL']]);
        $this->offer($game, '10.00');
        $this->offer($game, '11.00');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->getJson('/api/games/'.$game->id)->assertOk()->assertJsonCount(2, 'data.offers');
        $this->assertCount(3, $queries); // Route binding + offers + all sellers in one batch.
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('digital_keys', $query);
            $this->assertStringNotContainsString('key_value', $query);
        }
    }
}
