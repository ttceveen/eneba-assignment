<?php

namespace Tests\Feature;

use App\Models\DigitalKey;
use App\Models\Game;
use App\Models\Offer;
use App\Models\Seller;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DigitalKeyTest extends TestCase
{
    use RefreshDatabase;

    private function offer(): Offer
    {
        return Offer::create([
            'seller_id' => Seller::create(['name' => 'Seller fixture'])->id,
            'game_id' => Game::create(['name' => 'Game fixture', 'location' => ['GLOBAL']])->id,
            'price' => '9.99', 'quantity' => 7,
        ]);
    }

    public function test_key_is_encrypted_decrypted_and_hidden_including_nested_serialization(): void
    {
        $offer = $this->offer();
        $plaintext = 'TEST-FIXTURE-NOT-A-REAL-KEY';
        $key = DigitalKey::create(['offer_id' => $offer->id, 'key_value' => $plaintext]);
        $stored = DB::table('digital_keys')->where('id', $key->id)->value('key_value');

        // Boolean assertions keep even failed test output free of key values.
        $this->assertTrue(is_string($stored) && $stored !== $plaintext && ! str_contains($stored, $plaintext));
        $this->assertTrue(hash_equals($plaintext, $key->fresh()->key_value));
        $this->assertTrue(hash_equals($plaintext, Crypt::decryptString($stored)));
        $this->assertFalse(array_key_exists('key_value', $key->toArray()));
        $this->assertFalse(array_key_exists('key_value', json_decode($key->toJson(), true)));
        $this->assertFalse(array_key_exists('key_value', $offer->load('digitalKeys')->toArray()['digital_keys'][0]));
        $this->assertTrue($key->offer->is($offer));
        $this->assertTrue($offer->digitalKeys->sole()->is($key));
        $this->assertSame('available', $key->status);
        $this->assertSame('available', $key->fresh()->status);
        $this->assertNotNull($key->created_at);
        $this->assertNotNull($key->updated_at);
    }

    public function test_multiple_keys_and_all_statuses_do_not_synchronize_quantity(): void
    {
        $offer = $this->offer();
        $before = $offer->fresh()->getRawOriginal();
        foreach (['available', 'reserved', 'sold'] as $status) {
            $key = $offer->digitalKeys()->create(['key_value' => 'TEST-FIXTURE', 'status' => $status]);
            $this->assertSame($status, $key->fresh()->status);
        }
        $this->assertSame(3, $offer->digitalKeys()->count());
        $this->assertSame($before, $offer->fresh()->getRawOriginal());
        $key->update(['status' => 'available']);
        $this->assertSame($before, $offer->fresh()->getRawOriginal());
        $key->delete();
        $this->assertSame($before, $offer->fresh()->getRawOriginal());
    }

    public function test_database_status_default_and_offer_foreign_key(): void
    {
        $offer = $this->offer();
        $id = DB::table('digital_keys')->insertGetId([
            'offer_id' => $offer->id, 'key_value' => Crypt::encryptString('TEST-FIXTURE'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('available', DigitalKey::findOrFail($id)->status);
        $this->assertCount(1, Schema::getForeignKeys('digital_keys'));
        $this->expectException(QueryException::class);
        DigitalKey::create(['offer_id' => 999999, 'key_value' => 'TEST-FIXTURE']);
    }

    public function test_migrations_can_be_rolled_back_in_dependency_order_and_reapplied(): void
    {
        $keys = require database_path('migrations/2026_11_12_000006_create_digital_keys_table.php');
        $offers = require database_path('migrations/2026_11_12_000005_create_offers_table.php');
        $keys->down();
        $offers->down();
        $this->assertFalse(Schema::hasTable('digital_keys'));
        $this->assertFalse(Schema::hasTable('offers'));
        $this->assertTrue(Schema::hasTable('games'));
        $this->assertTrue(Schema::hasTable('sellers'));
        $offers->up();
        $keys->up();
        $this->assertTrue(Schema::hasTable('offers'));
        $this->assertTrue(Schema::hasTable('digital_keys'));
    }
}
