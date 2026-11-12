<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Offer;
use App\Models\Seller;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OfferTest extends TestCase
{
    use RefreshDatabase;

    private function offer(array $attributes = []): Offer
    {
        return Offer::create(array_replace([
            'seller_id' => Seller::create(['name' => 'Seller fixture'])->id,
            'game_id' => Game::create(['name' => 'Game fixture', 'location' => ['GLOBAL']])->id,
            'price' => '19.90', 'quantity' => 3,
        ], $attributes));
    }

    public function test_offer_creation_casts_and_relationships(): void
    {
        $offer = $this->offer();
        $this->assertIsInt($offer->id);
        $this->assertSame('19.90', $offer->fresh()->price);
        $this->assertSame(3, $offer->fresh()->quantity);
        $this->assertTrue($offer->is_active);
        $this->assertTrue($offer->fresh()->is_active);
        $this->assertTrue($offer->seller->offers->sole()->is($offer));
        $this->assertTrue($offer->game->offers->sole()->is($offer));
        $this->assertSame(0, $offer->digitalKeys()->count());
        $this->assertNotNull($offer->created_at);
        $this->assertNotNull($offer->updated_at);
    }

    public function test_different_sellers_can_offer_the_same_game(): void
    {
        $first = $this->offer();
        $second = Offer::create([
            'seller_id' => Seller::create(['name' => 'Another seller'])->id,
            'game_id' => $first->game_id, 'price' => '20.00', 'quantity' => 1,
        ]);
        $this->assertSame($first->game_id, $second->game_id);
        $this->assertSame(2, $first->game->offers()->count());
    }

    public function test_seller_game_pair_must_be_unique(): void
    {
        $offer = $this->offer();
        $this->expectException(QueryException::class);
        Offer::create(['seller_id' => $offer->seller_id, 'game_id' => $offer->game_id, 'price' => '1.00', 'quantity' => 1]);
    }

    public function test_database_active_default_and_zero_values(): void
    {
        $offer = $this->offer(['price' => '0', 'quantity' => 0]);
        $this->assertSame('0.00', $offer->fresh()->price);
        $id = DB::table('offers')->insertGetId([
            'seller_id' => $offer->seller_id,
            'game_id' => Game::create(['name' => 'Second game', 'location' => ['GLOBAL']])->id,
            'price' => '2.50', 'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue(Offer::findOrFail($id)->is_active);
        $offer->update(['is_active' => false]);
        $this->assertFalse($offer->fresh()->is_active);
    }

    public function test_foreign_keys_require_existing_seller_and_game(): void
    {
        $offer = $this->offer();
        $foreignKeys = Schema::getForeignKeys('offers');
        $this->assertCount(2, $foreignKeys);
        foreach (['seller_id', 'game_id'] as $field) {
            try {
                $offer->update([$field => 999999]);
                $this->fail('Missing offer foreign key constraint.');
            } catch (QueryException $exception) {
                $this->assertSame('23000', $exception->getCode());
                $offer->refresh();
            }
        }
    }
}
