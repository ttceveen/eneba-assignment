<?php

namespace Tests\Postgres;

use App\Exceptions\DuplicateDigitalKeyException;
use App\Models\DigitalKey;
use App\Models\Game;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DigitalKeyFingerprintTest extends TestCase
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
        config(['checkout.key_fingerprint_secret' => 'checkout-test-only-secret-32-bytes-minimum']);
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
        return Offer::create(['seller_id' => Seller::create(['name' => 'Fingerprint fixture'])->id,
            'game_id' => Game::create(['name' => 'Fingerprint fixture', 'location' => ['GLOBAL']])->id,
            'price' => '1.00', 'quantity' => 3]);
    }

    private function rejected(callable $write, string $expected): void
    {
        DB::statement('SAVEPOINT fingerprint_rejected');
        $actual = null;
        try {
            $write();
        } catch (QueryException $exception) {
            $actual = $exception->getCode();
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT fingerprint_rejected');
            DB::statement('RELEASE SAVEPOINT fingerprint_rejected');
        }
        $this->assertSame($expected, $actual, 'Unexpected PostgreSQL constraint outcome.');
    }

    public function test_new_key_automatically_gets_correct_hmac(): void
    {
        $key = $this->offer()->digitalKeys()->create(['key_value' => 'automatic-fixture']);
        $this->assertTrue(hash_equals(DigitalKey::fingerprint('automatic-fixture'), $key->fresh()->key_fingerprint));
        $this->assertTrue(hash_equals(hash_hmac('sha256', 'automatic-fixture', config('checkout.key_fingerprint_secret')), $key->key_fingerprint));
    }

    public function test_different_keys_have_different_fingerprints(): void
    {
        $offer = $this->offer();
        $a = $offer->digitalKeys()->create(['key_value' => 'different-fixture-a']);
        $b = $offer->digitalKeys()->create(['key_value' => 'different-fixture-b']);
        $this->assertFalse(hash_equals($a->key_fingerprint, $b->key_fingerprint));
    }

    public function test_same_plaintext_is_deterministic(): void
    {
        $this->assertTrue(hash_equals(DigitalKey::fingerprint('deterministic-fixture'), DigitalKey::fingerprint('deterministic-fixture')));
    }

    public function test_postgresql_unique_rejects_duplicate_independently_of_model(): void
    {
        $offer = $this->offer();
        $key = $offer->digitalKeys()->create(['key_value' => 'unique-fixture']);
        $this->rejected(fn () => DB::table('digital_keys')->insert([
            'offer_id' => $offer->id, 'key_value' => Crypt::encryptString('unique-fixture'),
            'key_fingerprint' => $key->key_fingerprint, 'created_at' => now(), 'updated_at' => now(),
        ]), '23505');
    }

    public function test_duplicate_content_across_offers_and_all_statuses_has_safe_error(): void
    {
        $first = $this->offer();
        $other = $this->offer();
        $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'pending_payment', 'total_amount' => '3.00', 'reserved_until' => now()->addMinutes(15)]);
        $item = $order->items()->create(['offer_id' => $first->id, 'quantity' => 3, 'unit_price' => '1.00']);
        foreach (['available', 'reserved', 'sold'] as $status) {
            $value = 'status-fixture-'.$status;
            $key = $first->digitalKeys()->create(['key_value' => $value, 'status' => $status,
                'order_item_id' => $status === 'available' ? null : $item->id,
                'reserved_until' => $status === 'reserved' ? now()->addMinutes(15) : null]);
            try {
                $other->digitalKeys()->create(['key_value' => $value]);
                $this->fail('Duplicate was accepted.');
            } catch (DuplicateDigitalKeyException $exception) {
                $this->assertStringContainsString('already exists', $exception->getMessage());
                $this->assertFalse(str_contains($exception->getMessage(), $value));
                $this->assertFalse(str_contains($exception->getMessage(), $key->key_fingerprint));
                $this->assertNull($exception->getPrevious());
            }
        }
        $this->assertSame(3, $first->digitalKeys()->count());
        $this->assertSame(0, $other->digitalKeys()->count());
    }

    public function test_postgresql_requires_fingerprint_for_direct_inserts_and_updates(): void
    {
        $offer = $this->offer();
        $this->rejected(fn () => DB::table('digital_keys')->insert(['offer_id' => $offer->id,
            'key_value' => Crypt::encryptString('missing-fixture'), 'created_at' => now(), 'updated_at' => now()]), '23502');
        $key = $offer->digitalKeys()->create(['key_value' => 'nonnull-fixture']);
        $this->rejected(fn () => DB::table('digital_keys')->where('id', $key->id)->update(['key_fingerprint' => null]), '23502');
        $this->assertTrue(collect(Schema::getIndexes('digital_keys'))->firstWhere('name', 'digital_keys_key_fingerprint_unique')['unique']);
    }

    public function test_repeated_backfill_preserves_ciphertext_and_timestamps(): void
    {
        $key = $this->offer()->digitalKeys()->create(['key_value' => 'idempotent-fixture']);
        $before = (array) DB::table('digital_keys')->where('id', $key->id)->first();
        foreach (range(1, 2) as $n) {
            $this->assertSame(0, Artisan::call('digital-keys:backfill-fingerprints', ['--allow-decryption' => true]));
            $this->assertStringContainsString('fingerprints written: 0', Artisan::output());
        }
        $this->assertTrue($before === (array) DB::table('digital_keys')->where('id', $key->id)->first());
    }

    public function test_ciphertext_and_serialization_do_not_expose_key_or_fingerprint(): void
    {
        $key = $this->offer()->digitalKeys()->create(['key_value' => 'encrypted-fixture']);
        $stored = DB::table('digital_keys')->where('id', $key->id)->value('key_value');
        $this->assertFalse(str_contains($stored, 'encrypted-fixture'));
        $this->assertTrue(hash_equals('encrypted-fixture', $key->fresh()->key_value));
        $this->assertArrayNotHasKey('key_value', $key->toArray());
        $this->assertArrayNotHasKey('key_fingerprint', $key->toArray());
    }

    public function test_missing_or_shared_secret_fails_explicitly_without_writing(): void
    {
        $offer = $this->offer();
        foreach ([null, 'short', config('app.key')] as $secret) {
            config(['checkout.key_fingerprint_secret' => $secret]);
            try {
                $offer->digitalKeys()->create(['key_value' => 'secret-fixture']);
                $this->fail('Invalid secret was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('DIGITAL_KEY_FINGERPRINT_SECRET', $exception->getMessage());
            }
        }
        config(['checkout.key_fingerprint_secret' => 'checkout-test-only-secret-32-bytes-minimum']);
        $this->assertSame(0, $offer->digitalKeys()->count());
    }

    public function test_not_null_migration_refuses_incomplete_backfill(): void
    {
        $migration = require database_path('migrations/2026_11_13_000003_require_digital_key_fingerprints.php');
        $migration->down();
        $offer = $this->offer();
        DB::table('digital_keys')->insert(['offer_id' => $offer->id, 'key_value' => Crypt::encryptString('legacy-null-fixture'), 'created_at' => now(), 'updated_at' => now()]);
        try {
            $migration->up();
            $this->fail('Incomplete backfill was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('backfill is incomplete', $exception->getMessage());
        }
        $this->assertSame(1, DB::table('digital_keys')->whereNull('key_fingerprint')->count());
        $this->assertSame(0, Artisan::call('digital-keys:backfill-fingerprints', ['--allow-decryption' => true]));
        $migration->up();
        $column = DB::selectOne("SELECT is_nullable FROM information_schema.columns WHERE table_schema='public' AND table_name='digital_keys' AND column_name='key_fingerprint'");
        $this->assertSame('NO', $column->is_nullable);
    }
}
