<?php

namespace Tests\Feature;

use App\Models\DigitalKey;
use App\Models\Game;
use App\Models\Offer;
use App\Models\Seller;
use Database\Seeders\DigitalKeySeeder;
use Database\Seeders\OfferSeeder;
use Database\Seeders\SellerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_are_idempotent_consistent_and_do_not_modify_games(): void
    {
        for ($i = 0; $i < 6; $i++) {
            Game::create(['name' => 'Seeder game '.$i, 'location' => ['GLOBAL']]);
        }
        $gamesBefore = Game::orderBy('id')->get()->map(fn ($game) => $game->getRawOriginal())->all();
        $this->seed([SellerSeeder::class, OfferSeeder::class, DigitalKeySeeder::class]);
        $this->assertDatabaseCount('sellers', 5);
        $this->assertSame(18, Offer::count());
        $this->assertSame((int) Offer::sum('quantity'), DigitalKey::count());
        $this->assertSame(0, DigitalKey::where('status', '!=', 'available')->count());
        $this->assertSame(0, Seller::where('is_active', false)->count());
        $this->assertSame(0, Offer::where('is_active', false)->count());
        $hashes = [];
        foreach (DigitalKey::all() as $key) {
            $this->assertTrue(str_starts_with($key->key_value, 'TEST-NOT-VALID-'));
            $hashes[] = hash('sha256', $key->key_value);
        }
        $this->assertSame(count($hashes), count(array_unique($hashes)));
        foreach (Game::with('offers')->get() as $game) {
            $this->assertGreaterThanOrEqual(2, $game->offers->count());
            $this->assertLessThanOrEqual(4, $game->offers->count());
            $this->assertSame($game->offers->count(), $game->offers->pluck('price')->unique()->count());
        }
        foreach (Offer::withCount('digitalKeys')->get() as $offer) {
            $this->assertSame($offer->quantity, $offer->digital_keys_count);
        }
        $before = [Seller::orderBy('id')->get()->toArray(), Offer::orderBy('id')->get()->toArray(), DigitalKey::orderBy('id')->get()->map(fn ($key) => hash('sha256', json_encode($key->getRawOriginal())))->all()];
        $this->seed([SellerSeeder::class, OfferSeeder::class, DigitalKeySeeder::class]);
        $after = [Seller::orderBy('id')->get()->toArray(), Offer::orderBy('id')->get()->toArray(), DigitalKey::orderBy('id')->get()->map(fn ($key) => hash('sha256', json_encode($key->getRawOriginal())))->all()];
        $this->assertTrue($before === $after, 'Repeat seeding changed existing marketplace records.');
        $this->assertSame($gamesBefore, Game::orderBy('id')->get()->map(fn ($game) => $game->getRawOriginal())->all());
    }

    public function test_key_seeding_repairs_a_missing_test_key_without_duplicate_plaintext(): void
    {
        Game::create(['name' => 'Repair fixture', 'location' => ['GLOBAL']]);
        $this->seed([SellerSeeder::class, OfferSeeder::class, DigitalKeySeeder::class]);
        DigitalKey::orderBy('id')->firstOrFail()->delete();
        $this->seed(DigitalKeySeeder::class);
        $this->assertSame((int) Offer::sum('quantity'), DigitalKey::count());
        $hashes = DigitalKey::all()->map(fn ($key) => hash('sha256', $key->key_value));
        $this->assertSame($hashes->count(), $hashes->unique()->count());
    }
}
