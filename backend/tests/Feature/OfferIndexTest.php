<?php

namespace Tests\Feature;

use App\Models\DigitalKey;
use App\Models\Game;
use App\Models\Offer;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfferIndexTest extends TestCase
{
    use RefreshDatabase;

    private function offer(): Offer
    {
        return Offer::create([
            'game_id' => Game::create(['name' => 'Game', 'location' => ['GLOBAL']])->id,
            'seller_id' => Seller::create(['name' => 'Seller'])->id,
            'price' => '12.34', 'quantity' => 2,
        ]);
    }

    public function test_one_multiple_duplicates_missing_and_empty_ids(): void
    {
        $a = $this->offer();
        $b = $this->offer();
        $this->getJson('/api/offers?ids='.$a->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.game.name', 'Game')->assertJsonPath('data.0.seller.name', 'Seller')
            ->assertJsonPath('data.0.price', '12.34')->assertJsonPath('data.0.currency', 'EUR');
        $this->getJson("/api/offers?ids={$a->id},{$b->id},{$a->id},999999")->assertOk()->assertJsonCount(2, 'data');
        foreach (['/api/offers', '/api/offers?ids=', '/api/offers?ids=999999'] as $url) {
            $this->getJson($url)->assertOk()->assertExactJson(['data' => []]);
        }
    }

    public function test_invalid_and_excessive_ids(): void
    {
        foreach (['0', '-1', '1.5', '1,,2', '1,', 'abc', '1%202', '01', '9223372036854775808', '1e2', implode(',', array_fill(0, 101, '1'))] as $ids) {
            $this->getJson('/api/offers?ids='.$ids)->assertUnprocessable()->assertJsonValidationErrors('ids');
        }
        $this->getJson('/api/offers?ids[]=1')->assertUnprocessable();
        $this->getJson('/api/offers?ids='.implode(',', range(1, 100)))->assertOk();
    }

    public function test_stock_and_activity_without_exposing_keys(): void
    {
        $offer = $this->offer();
        foreach (['available', 'reserved', 'sold'] as $status) {
            DigitalKey::create(['offer_id' => $offer->id, 'key_value' => 'SECRET-FIXTURE', 'status' => $status]);
        }
        $url = '/api/offers?ids='.$offer->id;
        $response = $this->getJson($url)->assertOk()->assertJsonPath('data.0.quantity', 1)->assertJsonPath('data.0.available', true);
        $this->assertSame(['id', 'game', 'seller', 'price', 'currency', 'quantity', 'available'], array_keys($response->json('data.0')));
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        foreach ([$offer, $offer->game, $offer->seller] as $model) {
            $model->update(['is_active' => false]);
            $this->getJson($url)->assertJsonPath('data.0.available', false);
            $model->update(['is_active' => true]);
        }
        DigitalKey::create(['offer_id' => $offer->id, 'key_value' => 'SECOND']);
        DigitalKey::create(['offer_id' => $offer->id, 'key_value' => 'THIRD']);
        $this->getJson($url)->assertJsonPath('data.0.quantity', 2);
        $offer->update(['quantity' => 0]);
        $this->getJson($url)->assertJsonPath('data.0.available', false)->assertJsonPath('data.0.quantity', 0);
        $empty = $this->offer();
        $this->getJson('/api/offers?ids='.$empty->id)->assertJsonPath('data.0.quantity', 0)->assertJsonPath('data.0.available', false);
    }

    public function test_query_count_is_constant_without_loading_key_values(): void
    {
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $this->offer()->id;
        }
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = strtolower($event->sql);
        });
        $this->getJson('/api/offers?ids='.$ids[0])->assertOk();
        $this->assertCount(3, $queries);
        $queries = [];
        $this->getJson('/api/offers?ids='.implode(',', $ids))->assertOk()->assertJsonCount(10, 'data');
        $this->assertCount(3, $queries);
        $this->assertStringContainsString('count(*)', $queries[0]);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('key_value', $query);
        }
    }
}
