<?php

namespace Tests\Postgres;

use App\Models\DigitalKey;
use App\Models\Game;
use App\Models\Offer;
use App\Models\Seller;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// Separate guarded suite: it never relaxes the main suite's SQLite-only protection.
class OfferDigitalKeyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        foreach (['SEARCH_TEST_SANDBOX' => '1', 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'search-postgres', 'DB_DATABASE' => 'eneba_search_test', 'DB_USERNAME' => 'eneba_search_test',
            'DATABASE_URL' => '', 'DB_URL' => ''] as $key => $value) {
            if (getenv($key) !== $value) {
                throw new RuntimeException('Refusing tests outside the disposable Compose database.');
            }
        }
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $config = config('database.connections.'.config('database.default'));
        if (! $app->environment('testing') || $config['driver'] !== 'pgsql'
            || $config['host'] !== 'search-postgres' || $config['database'] !== 'eneba_search_test'
            || $config['username'] !== 'eneba_search_test' || ! empty($config['url'])) {
            throw new RuntimeException('Unsafe resolved test connection.');
        }
        $identity = DB::selectOne('SELECT current_database() AS db, current_user AS username');
        if ($identity->db !== 'eneba_search_test' || $identity->username !== 'eneba_search_test'
            || DB::selectOne("SELECT count(*) AS n FROM information_schema.tables WHERE table_schema = 'public'")->n !== 0) {
            throw new RuntimeException('Tests require a new, empty disposable database.');
        }
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Disposable migration failed.');
        }
    }

    protected function setUp(): void
    {
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
    }

    private function offer(): Offer
    {
        return Offer::create([
            'seller_id' => Seller::create(['name' => 'Seller fixture'])->id,
            'game_id' => Game::create(['name' => 'Game fixture', 'location' => ['GLOBAL']])->id,
            'price' => '12.50', 'quantity' => 4,
        ]);
    }

    private function rejected(callable $write, string $sqlState): void
    {
        DB::statement('SAVEPOINT rejected_write');
        $actual = null;
        try {
            $write();
        } catch (QueryException $exception) {
            $actual = $exception->getCode();
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT rejected_write');
            DB::statement('RELEASE SAVEPOINT rejected_write');
        }
        // Do not print SQL, bindings or encrypted/plaintext keys on failure.
        $this->assertSame($sqlState, $actual, 'Unexpected database constraint outcome.');
    }

    public static function invalidValues(): array
    {
        return [
            'negative price' => ['offers', 'price', '-0.01', '23514'],
            'negative quantity' => ['offers', 'quantity', -1, '23514'],
            'price overflow' => ['offers', 'price', '100000000.00', '22003'],
            'null price' => ['offers', 'price', null, '23502'],
            'null quantity' => ['offers', 'quantity', null, '23502'],
            'null active' => ['offers', 'is_active', null, '23502'],
            'null seller' => ['offers', 'seller_id', null, '23502'],
            'null game' => ['offers', 'game_id', null, '23502'],
            'missing seller' => ['offers', 'seller_id', 999999, '23503'],
            'missing game' => ['offers', 'game_id', 999999, '23503'],
            'null offer created' => ['offers', 'created_at', null, '23502'],
            'null offer updated' => ['offers', 'updated_at', null, '23502'],
            'invalid status' => ['digital_keys', 'status', 'pending', '23514'],
            'null status' => ['digital_keys', 'status', null, '23502'],
            'long status' => ['digital_keys', 'status', str_repeat('x', 21), '22001'],
            'null key' => ['digital_keys', 'key_value', null, '23502'],
            'null offer' => ['digital_keys', 'offer_id', null, '23502'],
            'missing offer' => ['digital_keys', 'offer_id', 999999, '23503'],
            'null key created' => ['digital_keys', 'created_at', null, '23502'],
            'null key updated' => ['digital_keys', 'updated_at', null, '23502'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_postgres_constraints(string $table, string $column, mixed $value, string $state): void
    {
        $offer = $this->offer();
        $id = $offer->id;
        if ($table === 'digital_keys') {
            $id = DigitalKey::create(['offer_id' => $offer->id, 'key_value' => 'TEST-FIXTURE'])->id;
        }
        $this->rejected(fn () => DB::table($table)->where('id', $id)->update([$column => $value]), $state);
    }

    public function test_schema_types_precision_defaults_and_required_columns(): void
    {
        $offers = DB::select("SELECT column_name, udt_name, is_nullable, numeric_precision, numeric_scale, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'offers' ORDER BY ordinal_position");
        $keys = DB::select("SELECT column_name, udt_name, is_nullable, character_maximum_length, column_default FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'digital_keys' ORDER BY ordinal_position");
        $this->assertSame(['id', 'seller_id', 'game_id', 'price', 'quantity', 'is_active', 'created_at', 'updated_at'], array_column($offers, 'column_name'));
        $this->assertSame(['int8', 'int8', 'int8', 'numeric', 'int4', 'bool', 'timestamptz', 'timestamptz'], array_column($offers, 'udt_name'));
        $this->assertSame(['id', 'offer_id', 'key_value', 'status', 'created_at', 'updated_at'], array_column($keys, 'column_name'));
        $this->assertSame(['int8', 'int8', 'text', 'varchar', 'timestamptz', 'timestamptz'], array_column($keys, 'udt_name'));
        $this->assertSame(['NO'], array_values(array_unique(array_column($offers, 'is_nullable'))));
        $this->assertSame(['NO'], array_values(array_unique(array_column($keys, 'is_nullable'))));
        $this->assertSame(10, $offers[3]->numeric_precision);
        $this->assertSame(2, $offers[3]->numeric_scale);
        $this->assertSame(20, $keys[3]->character_maximum_length);
        $this->assertSame('true', $offers[5]->column_default);
        $this->assertStringContainsString('available', $keys[3]->column_default);
        $this->assertStringContainsString('nextval', $offers[0]->column_default);
        $this->assertStringContainsString('nextval', $keys[0]->column_default);
    }

    public function test_only_approved_indexes_and_constraints_exist(): void
    {
        $offers = collect(Schema::getIndexes('offers'))->keyBy('name');
        $keys = collect(Schema::getIndexes('digital_keys'))->keyBy('name');
        $this->assertEqualsCanonicalizing(['offers_pkey', 'offers_seller_id_game_id_unique', 'idx_offers_available_game_price'], $offers->keys()->all());
        $this->assertEqualsCanonicalizing(['digital_keys_pkey', 'idx_digital_keys_available'], $keys->keys()->all());
        $this->assertSame(['seller_id', 'game_id'], $offers['offers_seller_id_game_id_unique']['columns']);
        $this->assertTrue($offers['offers_seller_id_game_id_unique']['unique']);
        $this->assertSame(['game_id', 'price'], $offers['idx_offers_available_game_price']['columns']);
        $this->assertSame(['offer_id', 'id'], $keys['idx_digital_keys_available']['columns']);
        $predicates = collect(DB::select("SELECT indexrelid::regclass::text AS name, pg_get_expr(indpred, indrelid) AS predicate FROM pg_index WHERE indrelid IN ('offers'::regclass, 'digital_keys'::regclass)"))->keyBy('name');
        $this->assertStringContainsString('is_active = true', $predicates['idx_offers_available_game_price']->predicate);
        $this->assertStringContainsString('quantity > 0', $predicates['idx_offers_available_game_price']->predicate);
        $this->assertStringContainsString('available', $predicates['idx_digital_keys_available']->predicate);
        $this->assertStringContainsString('status', $predicates['idx_digital_keys_available']->predicate);
        $this->assertCount(2, Schema::getForeignKeys('offers'));
        $this->assertCount(1, Schema::getForeignKeys('digital_keys'));
        $checks = DB::select("SELECT conname FROM pg_constraint WHERE conrelid IN ('offers'::regclass, 'digital_keys'::regclass) AND contype = 'c'");
        $this->assertEqualsCanonicalizing(['offers_price_nonnegative', 'offers_quantity_nonnegative', 'digital_keys_status_valid'], array_column($checks, 'conname'));
    }

    public function test_uniqueness_foreign_keys_and_inverse_relationships(): void
    {
        $first = $this->offer();
        $this->rejected(fn () => Offer::create(['seller_id' => $first->seller_id, 'game_id' => $first->game_id, 'price' => '1.00', 'quantity' => 1]), '23505');
        $second = Offer::create(['seller_id' => Seller::create(['name' => 'Other seller'])->id, 'game_id' => $first->game_id, 'price' => '0.00', 'quantity' => 0]);
        $this->assertSame(2, $first->game->offers()->count());
        $this->assertTrue($first->seller->offers->sole()->is($first));
        $this->assertSame('12.50', $first->fresh()->price);
        $this->assertSame('0.00', $second->fresh()->price);
        $this->assertTrue($first->fresh()->is_active);
        $this->rejected(fn () => $first->seller->delete(), '23503');
        $this->rejected(fn () => $first->game->delete(), '23503');
    }

    public function test_key_encryption_statuses_hidden_serialization_and_independent_quantity(): void
    {
        $offer = $this->offer();
        $before = $offer->fresh()->getRawOriginal();
        $plaintext = 'TEST-FIXTURE-NOT-A-REAL-KEY';
        $first = $offer->digitalKeys()->create(['key_value' => $plaintext]);
        $stored = DB::table('digital_keys')->where('id', $first->id)->value('key_value');
        $this->assertTrue(is_string($stored) && $stored !== $plaintext && ! str_contains($stored, $plaintext));
        $this->assertTrue(hash_equals($plaintext, $first->fresh()->key_value));
        $this->assertTrue(hash_equals($plaintext, Crypt::decryptString($stored)));
        $this->assertTrue($first->offer->is($offer));
        $this->assertSame('available', $first->status);
        $this->assertSame('available', $first->fresh()->status);
        foreach (['available', 'reserved', 'sold'] as $status) {
            $key = $offer->digitalKeys()->create(['key_value' => 'TEST-FIXTURE', 'status' => $status]);
            $this->assertSame($status, $key->fresh()->status);
        }
        $this->assertSame(4, $offer->digitalKeys()->count());
        $this->assertFalse(array_key_exists('key_value', $first->toArray()));
        $this->assertFalse(array_key_exists('key_value', json_decode($first->toJson(), true)));
        $this->assertFalse(array_key_exists('key_value', $offer->load('digitalKeys')->toArray()['digital_keys'][0]));
        $this->assertSame($before, $offer->fresh()->getRawOriginal());
        $first->update(['status' => 'sold']);
        $this->assertSame($before, $offer->fresh()->getRawOriginal());
        $this->rejected(fn () => $offer->delete(), '23503');
        $first->delete();
        $this->assertSame($before, $offer->fresh()->getRawOriginal());
        $this->assertNotNull($key->created_at);
        $this->assertNotNull($key->updated_at);
    }

    public function test_database_defaults_bypassing_eloquent_attributes(): void
    {
        $offer = $this->offer();
        $id = DB::table('offers')->insertGetId([
            'seller_id' => Seller::create(['name' => 'Default fixture'])->id, 'game_id' => $offer->game_id,
            'price' => '0.00', 'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue(Offer::findOrFail($id)->is_active);
        $keyId = DB::table('digital_keys')->insertGetId([
            'offer_id' => $id, 'key_value' => Crypt::encryptString('TEST-FIXTURE'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame('available', DigitalKey::findOrFail($keyId)->status);
    }

    public function test_migration_rollback_and_reapply_preserve_parent_tables(): void
    {
        $offer = $this->offer();
        $keys = require database_path('migrations/2026_11_12_000006_create_digital_keys_table.php');
        $offers = require database_path('migrations/2026_11_12_000005_create_offers_table.php');
        $keys->down();
        $offers->down();
        $this->assertFalse(Schema::hasTable('digital_keys'));
        $this->assertFalse(Schema::hasTable('offers'));
        $this->assertTrue(Game::whereKey($offer->game_id)->exists());
        $this->assertTrue(Seller::whereKey($offer->seller_id)->exists());
        $offers->up();
        $keys->up();
        $this->assertTrue(Schema::hasTable('offers'));
        $this->assertTrue(Schema::hasTable('digital_keys'));
        $this->assertCount(3, Schema::getIndexes('offers'));
        $this->assertCount(2, Schema::getIndexes('digital_keys'));
    }
}
