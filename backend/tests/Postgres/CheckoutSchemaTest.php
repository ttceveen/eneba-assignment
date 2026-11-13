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
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CheckoutSchemaTest extends TestCase
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

    private function fixtures(): array
    {
        $user = User::factory()->create();
        $offer = Offer::create(['seller_id' => Seller::create(['name' => 'Fixture'])->id,
            'game_id' => Game::create(['name' => 'Fixture', 'location' => ['GLOBAL']])->id,
            'price' => '12.50', 'quantity' => 4]);
        $order = Order::create(['user_id' => $user->id, 'status' => 'pending_payment',
            'total_amount' => '25.00', 'reserved_until' => now()->addMinutes(15)]);
        $item = $order->items()->create(['offer_id' => $offer->id, 'quantity' => 2, 'unit_price' => '12.50']);

        return [$user, $offer, $order, $item];
    }

    private function rejected(callable $write, string $expected): void
    {
        DB::statement('SAVEPOINT invalid_checkout_write');
        $actual = null;
        try {
            $write();
        } catch (QueryException|DuplicateDigitalKeyException $exception) {
            $actual = (string) $exception->getCode();
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT invalid_checkout_write');
            DB::statement('RELEASE SAVEPOINT invalid_checkout_write');
        }
        $this->assertSame($expected, $actual, 'Unexpected constraint outcome.');
    }

    public function test_orders_items_snapshot_casts_and_all_relationships(): void
    {
        [$user, $offer, $order, $item] = $this->fixtures();
        $second = $order->items()->create(['offer_id' => $offer->id, 'quantity' => 1, 'unit_price' => '10.00']);
        $key = $item->digitalKeys()->create(['offer_id' => $offer->id, 'key_value' => 'checkout-fixture',
            'status' => 'reserved', 'reserved_until' => $order->reserved_until]);
        $this->assertTrue($order->user->is($user));
        $this->assertTrue($user->orders->sole()->is($order));
        $this->assertCount(2, $order->items);
        $this->assertTrue($item->order->is($order));
        $this->assertTrue($item->offer->is($offer));
        $this->assertCount(2, $offer->orderItems);
        $this->assertTrue($key->orderItem->is($item));
        $this->assertTrue($key->offer->is($offer));
        $this->assertSame('25.00', $order->fresh()->total_amount);
        $this->assertSame('EUR', $order->fresh()->currency);
        $this->assertTrue($order->reserved_until->equalTo($key->fresh()->reserved_until));
        $offer->update(['price' => '99.00', 'is_active' => false]);
        $this->assertSame('12.50', $item->fresh()->unit_price);
        $this->assertTrue($second->fresh()->exists);
        $this->assertArrayNotHasKey('key_value', $key->toArray());
        $this->assertArrayNotHasKey('key_fingerprint', $key->toArray());
        foreach (['orders', 'order_items', 'payments'] as $table) {
            $columns = DB::select("SELECT udt_name, is_nullable FROM information_schema.columns WHERE table_name = ? AND column_name IN ('created_at','updated_at')", [$table]);
            $this->assertCount(2, $columns);
            $this->assertSame(['timestamptz'], array_values(array_unique(array_column($columns, 'udt_name'))));
            $this->assertSame(['NO'], array_values(array_unique(array_column($columns, 'is_nullable'))));
        }
    }

    public function test_payment_attempts_partial_uniqueness_and_provider_ids(): void
    {
        [, , $order] = $this->fixtures();
        $attributes = ['provider' => 'fake_card', 'status' => 'pending', 'amount' => '25.00', 'currency' => 'EUR'];
        $pending = $order->payments()->create($attributes);
        $this->assertTrue($pending->order->is($order));
        $this->assertSame('25.00', $pending->amount);
        $this->rejected(fn () => $order->payments()->create($attributes), '23505');
        $pending->update(['status' => 'failed']);
        $retry = $order->payments()->create($attributes);
        $retry->update(['status' => 'succeeded', 'external_id' => 'fixture-payment']);
        $this->rejected(fn () => $order->payments()->create(array_replace($attributes, ['status' => 'succeeded'])), '23505');
        $this->rejected(fn () => $order->payments()->create(array_replace($attributes, ['status' => 'failed', 'external_id' => 'fixture-payment'])), '23505');
        $order->payments()->create(array_replace($attributes, ['provider' => 'another_provider', 'status' => 'failed', 'external_id' => 'fixture-payment']));
        $order->payments()->create(array_replace($attributes, ['status' => 'failed']));
        $this->assertCount(4, $order->fresh()->payments);
    }

    public function test_checks_and_foreign_keys_preserve_history(): void
    {
        [$user, $offer, $order, $item] = $this->fixtures();
        $payment = $order->payments()->create(['provider' => 'fixture', 'status' => 'failed', 'amount' => '1.00', 'currency' => 'EUR']);
        foreach ([['orders', $order->id, 'status', 'unknown'], ['orders', $order->id, 'total_amount', '-0.01'],
            ['order_items', $item->id, 'quantity', 0], ['order_items', $item->id, 'quantity', -1],
            ['order_items', $item->id, 'unit_price', '-0.01'], ['payments', $payment->id, 'status', 'unknown'],
            ['payments', $payment->id, 'amount', '-0.01']] as [$table, $id, $field, $value]) {
            $this->rejected(fn () => DB::table($table)->where('id', $id)->update([$field => $value]), '23514');
        }
        foreach ([['orders', $order->id, 'user_id'], ['order_items', $item->id, 'order_id'],
            ['order_items', $item->id, 'offer_id'], ['payments', $payment->id, 'order_id']] as [$table, $id, $field]) {
            $this->rejected(fn () => DB::table($table)->where('id', $id)->update([$field => 999999]), '23503');
        }
        foreach ([$user, $order, $offer] as $model) {
            $this->rejected(fn () => $model->delete(), '23503');
        }
    }

    public function test_key_state_checks_and_matching_offer(): void
    {
        [, $offer, $order, $item] = $this->fixtures();
        $key = DigitalKey::create(['offer_id' => $offer->id, 'key_value' => 'state-fixture']);
        foreach ([['status' => 'reserved'], ['status' => 'sold'], ['order_item_id' => $item->id],
            ['reserved_until' => now()], ['status' => 'reserved', 'order_item_id' => $item->id]] as $update) {
            $this->rejected(fn () => DB::table('digital_keys')->where('id', $key->id)->update($update), '23514');
        }
        $key->update(['status' => 'reserved', 'order_item_id' => $item->id, 'reserved_until' => $order->reserved_until]);
        $key->update(['status' => 'sold', 'reserved_until' => null]);
        $this->assertSame('sold', $key->fresh()->status);
        $otherOffer = Offer::create(['seller_id' => $offer->seller_id,
            'game_id' => Game::create(['name' => 'Other', 'location' => ['GLOBAL']])->id, 'price' => '1.00', 'quantity' => 0]);
        $otherItem = $order->items()->create(['offer_id' => $otherOffer->id, 'quantity' => 1, 'unit_price' => '1.00']);
        $this->rejected(fn () => DB::table('digital_keys')->where('id', $key->id)->update(['order_item_id' => $otherItem->id]), '23503');
        $this->rejected(fn () => $item->delete(), '23503');
        $key->update(['status' => 'available', 'order_item_id' => null]);
        $this->assertNull($key->fresh()->reserved_until);
    }

    public function test_hmac_uniqueness_secret_and_hidden_values(): void
    {
        [, $offer] = $this->fixtures();
        $key = DigitalKey::create(['offer_id' => $offer->id, 'key_value' => 'hmac-fixture']);
        $this->assertTrue(hash_equals(hash_hmac('sha256', 'hmac-fixture', config('checkout.key_fingerprint_secret')), $key->key_fingerprint));
        $this->assertTrue(! hash_equals(hash('sha256', 'hmac-fixture'), $key->key_fingerprint));
        $this->rejected(fn () => DigitalKey::create(['offer_id' => $offer->id, 'key_value' => 'hmac-fixture']), '23505');
        $this->rejected(fn () => DB::table('digital_keys')->where('id', $key->id)->update(['key_fingerprint' => 'invalid']), '23514');
        config(['checkout.key_fingerprint_secret' => null]);
        try {
            DigitalKey::create(['offer_id' => $offer->id, 'key_value' => 'no-secret-fixture']);
            $this->fail('Missing fingerprint secret was accepted.');
        } catch (RuntimeException) {
            $this->assertSame(1, $offer->digitalKeys()->count());
        } finally {
            config(['checkout.key_fingerprint_secret' => 'checkout-test-only-secret-32-bytes-minimum']);
        }
    }

    public function test_backfill_requires_authorization_and_preserves_ciphertext_timestamps(): void
    {
        $requiredFingerprint = require database_path('migrations/2026_11_13_000003_require_digital_key_fingerprints.php');
        $requiredFingerprint->down();
        [, $offer] = $this->fixtures();
        $id = DB::table('digital_keys')->insertGetId(['offer_id' => $offer->id, 'key_value' => Crypt::encryptString('legacy-fixture'), 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $before = DB::table('digital_keys')->where('id', $id)->first();
        $this->assertSame(1, Artisan::call('digital-keys:backfill-fingerprints'));
        $this->assertNull(DB::table('digital_keys')->where('id', $id)->value('key_fingerprint'));
        $this->assertSame(0, Artisan::call('digital-keys:backfill-fingerprints', ['--allow-decryption' => true, '--dry-run' => true]));
        $this->assertNull(DB::table('digital_keys')->where('id', $id)->value('key_fingerprint'));
        $this->assertSame(0, Artisan::call('digital-keys:backfill-fingerprints', ['--allow-decryption' => true]));
        $after = DB::table('digital_keys')->where('id', $id)->first();
        $this->assertTrue(hash_equals($before->key_value, $after->key_value));
        $this->assertSame($before->updated_at, $after->updated_at);
        $this->assertNotNull($after->key_fingerprint);
    }

    public function test_backfill_duplicate_rolls_back_all_updates(): void
    {
        $requiredFingerprint = require database_path('migrations/2026_11_13_000003_require_digital_key_fingerprints.php');
        $requiredFingerprint->down();
        [, $offer] = $this->fixtures();
        foreach (range(1, 2) as $n) {
            DB::table('digital_keys')->insert(['offer_id' => $offer->id, 'key_value' => Crypt::encryptString('duplicate-legacy-fixture'), 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertSame(1, Artisan::call('digital-keys:backfill-fingerprints', ['--allow-decryption' => true]));
        $this->assertSame(0, DB::table('digital_keys')->whereNotNull('key_fingerprint')->count());
    }

    public function test_rollback_refuses_to_discard_history_or_fingerprints(): void
    {
        [, $offer] = $this->fixtures();
        $key = DigitalKey::create(['offer_id' => $offer->id, 'key_value' => 'rollback-fixture']);
        foreach (['2026_11_13_000002_extend_digital_keys_for_checkout.php', '2026_11_13_000001_create_checkout_tables.php'] as $file) {
            $migration = require database_path('migrations/'.$file);
            try {
                $migration->down();
                $this->fail('Rollback discarded checkout data.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('refusing', $exception->getMessage());
            }
        }
        $this->assertTrue($key->fresh()->exists);
    }

    public function test_migrations_preserve_legacy_data_and_allow_empty_checkout_rollback(): void
    {
        $requiredFingerprint = require database_path('migrations/2026_11_13_000003_require_digital_key_fingerprints.php');
        $requiredFingerprint->down();
        $keys = require database_path('migrations/2026_11_13_000002_extend_digital_keys_for_checkout.php');
        $checkout = require database_path('migrations/2026_11_13_000001_create_checkout_tables.php');
        $keys->down();
        $checkout->down();
        $offer = Offer::create(['seller_id' => Seller::create(['name' => 'Legacy'])->id,
            'game_id' => Game::create(['name' => 'Legacy', 'location' => ['GLOBAL']])->id, 'price' => '3.00', 'quantity' => 1]);
        $id = DB::table('digital_keys')->insertGetId(['offer_id' => $offer->id, 'key_value' => Crypt::encryptString('preservation-fixture'), 'created_at' => now(), 'updated_at' => now()]);
        $before = (array) DB::table('digital_keys')->where('id', $id)->first();
        $checkout->up();
        $keys->up();
        $after = (array) DB::table('digital_keys')->where('id', $id)->first();
        foreach ($before as $column => $value) {
            $this->assertTrue($value === $after[$column], 'Existing column changed.');
        }
        $this->assertNull($after['key_fingerprint']);
        $keys->down();
        $checkout->down();
        $this->assertTrue(DB::table('digital_keys')->where('id', $id)->exists());
        $checkout->up();
        $keys->up();
    }
}
