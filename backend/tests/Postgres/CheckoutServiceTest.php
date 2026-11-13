<?php

namespace Tests\Postgres;

use App\Exceptions\CheckoutConflictException;
use App\Models\DigitalKey;
use App\Models\Game;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Seller;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\InventoryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CheckoutServiceTest extends TestCase
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

    private bool $transactional;

    protected function setUp(): void
    {
        $this->transactional = ! str_contains($this->name(), 'concurrent');
        if ($this->transactional) {
            DB::beginTransaction();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->transactional) {
            DB::rollBack();
        } else {
            // This suite is guarded against every database except its disposable sandbox.
            foreach (['personal_access_tokens', 'digital_keys', 'payments', 'order_items', 'orders', 'offers', 'game_popularities', 'games', 'sellers', 'users'] as $table) {
                DB::table($table)->delete();
            }
        }
    }

    private function service(): CheckoutService
    {
        return app(CheckoutService::class);
    }

    private function offer(int $stock = 3, string $price = '12.34'): Offer
    {
        $offer = Offer::create(['seller_id' => Seller::create(['name' => 'Checkout fixture'])->id,
            'game_id' => Game::create(['name' => 'Checkout fixture', 'location' => ['GLOBAL']])->id,
            'price' => $price, 'quantity' => $stock]);
        foreach (range(1, max(1, $stock)) as $n) {
            if ($stock > 0) {
                $offer->digitalKeys()->create(['key_value' => 'CHECKOUT-SYNTHETIC-'.$offer->id.'-'.$n]);
            }
        }

        return $offer;
    }

    private function create(User $user, Offer $offer, int $quantity = 1, string $key = 'fixture-request'): Order
    {
        return $this->service()->create($user, [['offer_id' => $offer->id, 'quantity' => $quantity]], $key);
    }

    private function expire(Order $order): void
    {
        $order->update(['reserved_until' => now()->subMinute()]);
        DB::table('digital_keys')->whereIn('order_item_id', $order->items()->pluck('id'))
            ->where('status', 'reserved')->update(['reserved_until' => $order->reserved_until]);
    }

    public function test_one_item_reserves_exact_keys_and_fifteen_minutes(): void
    {
        Carbon::setTestNow('2026-11-13 12:00:00 UTC');
        $offer = $this->offer();
        $order = $this->create(User::factory()->create(), $offer, 2);
        $this->assertSame('pending_payment', $order->status);
        $this->assertSame('24.68', $order->total_amount);
        $this->assertSame('EUR', $order->currency);
        $this->assertTrue(now()->addMinutes(15)->equalTo($order->reserved_until));
        $this->assertSame(1, $offer->fresh()->quantity);
        $keys = $order->items->sole()->digitalKeys;
        $this->assertCount(2, $keys);
        foreach ($keys as $key) {
            $this->assertSame('reserved', $key->status);
            $this->assertTrue($key->reserved_until->equalTo($order->reserved_until));
        }
        $deadline = $order->reserved_until->toISOString();
        $this->assertSame($deadline, $this->service()->get($order->user, $order->id)->reserved_until->toISOString());
    }

    public function test_multiple_items_exact_money_and_snapshot_price(): void
    {
        $a = $this->offer(3, '0.10');
        $b = $this->offer(2, '0.20');
        $order = $this->service()->create(User::factory()->create(), [
            ['offer_id' => $b->id, 'quantity' => 2], ['offer_id' => $a->id, 'quantity' => 3],
        ], 'multiple');
        $this->assertSame('0.70', $order->total_amount);
        $this->assertCount(2, $order->items);
        $a->update(['price' => '99.00']);
        $this->assertSame('0.10', $order->items()->where('offer_id', $a->id)->firstOrFail()->unit_price);
        $this->assertSame(0, $a->fresh()->quantity);
        $this->assertSame(0, $b->fresh()->quantity);
    }

    public static function invalidCarts(): array
    {
        return ['empty' => [[]], 'zero' => [[['offer_id' => 1, 'quantity' => 0]]],
            'negative' => [[['offer_id' => 1, 'quantity' => -1]]], 'fraction' => [[['offer_id' => 1, 'quantity' => 1.5]]],
            'duplicate' => [[['offer_id' => 1, 'quantity' => 1], ['offer_id' => 1, 'quantity' => 1]]],
            'client price' => [[['offer_id' => 1, 'quantity' => 1, 'price' => '0.01']]],
            'too many' => [array_fill(0, 21, ['offer_id' => 1, 'quantity' => 1])]];
    }

    #[DataProvider('invalidCarts')]
    public function test_invalid_carts_are_rejected(array $items): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->create(User::factory()->create(), $items, 'invalid');
    }

    public static function inactiveModels(): array
    {
        return [['offer'], ['seller'], ['game']];
    }

    #[DataProvider('inactiveModels')]
    public function test_inactive_offer_seller_or_game_is_rejected(string $model): void
    {
        $offer = $this->offer();
        ($model === 'offer' ? $offer : $offer->$model)->update(['is_active' => false]);
        try {
            $this->create(User::factory()->create(), $offer);
            $this->fail('Inactive stock accepted.');
        } catch (CheckoutConflictException) {
            $this->assertSame(0, Order::count());
            $this->assertSame(3, $offer->fresh()->quantity);
            $this->assertSame(0, DigitalKey::where('status', 'reserved')->count());
        }
    }

    public function test_insufficient_stock_rolls_back_every_item_and_counter(): void
    {
        $a = $this->offer(2);
        $b = $this->offer(1);
        try {
            $this->service()->create(User::factory()->create(), [['offer_id' => $a->id, 'quantity' => 1], ['offer_id' => $b->id, 'quantity' => 2]], 'rollback');
            $this->fail('Short stock accepted.');
        } catch (CheckoutConflictException) {
            $this->assertSame(0, Order::count());
            $this->assertSame(0, DB::table('order_items')->count());
            $this->assertSame(0, DigitalKey::where('status', 'reserved')->count());
            $this->assertSame(2, $a->fresh()->quantity);
            $this->assertSame(1, $b->fresh()->quantity);
        }
    }

    public function test_missing_offer_is_validation_error_and_money_overflow_is_rejected(): void
    {
        $user = User::factory()->create();
        foreach ([[['offer_id' => 999999, 'quantity' => 1]],
            [['offer_id' => $this->offer(1, '99999999.99')->id, 'quantity' => 2147483647]]] as $items) {
            try {
                $this->service()->create($user, $items, 'validation-limit');
                $this->fail('Invalid offer or overflowing amount accepted.');
            } catch (ValidationException) {
                $this->assertSame(0, Order::count());
                $this->assertSame(0, DigitalKey::where('status', 'reserved')->count());
            }
        }
    }

    public function test_inventory_failure_rolls_back_its_savepoint_even_if_caller_catches(): void
    {
        $a = $this->offer(1);
        $b = $this->offer(0);
        $order = Order::create(['user_id' => User::factory()->create()->id, 'status' => 'pending_payment',
            'total_amount' => '24.68', 'reserved_until' => now()->addMinutes(15)]);
        foreach ([$a, $b] as $offer) {
            $order->items()->create(['offer_id' => $offer->id, 'quantity' => 1, 'unit_price' => '12.34']);
        }
        try {
            app(InventoryService::class)->reserve($order);
            $this->fail('Incomplete reservation accepted.');
        } catch (CheckoutConflictException) {
            $this->assertSame(1, $a->fresh()->quantity);
            $this->assertSame(0, DigitalKey::where('status', 'reserved')->count());
        }
    }

    public function test_source_keys_not_only_counter_are_checked(): void
    {
        $offer = $this->offer(1);
        $offer->update(['quantity' => 3]);
        try {
            $this->create(User::factory()->create(), $offer, 2);
            $this->fail('Missing concrete keys accepted.');
        } catch (CheckoutConflictException) {
            $this->assertSame(3, $offer->fresh()->quantity);
            $this->assertSame(0, Order::count());
        }
    }

    public function test_cancel_restores_stock_once_and_blocks_double_reservation(): void
    {
        $offer = $this->offer(2);
        $user = User::factory()->create();
        $order = $this->create($user, $offer, 2);
        try {
            app(InventoryService::class)->reserve($order);
            $this->fail('Order was reserved twice.');
        } catch (CheckoutConflictException) {
            $this->assertSame(0, $offer->fresh()->quantity);
        }
        foreach (range(1, 2) as $n) {
            $this->assertSame('cancelled', $this->service()->cancel($user, $order->id)->status);
            $this->assertSame(2, $offer->fresh()->quantity);
        }
        $this->assertSame(0, DigitalKey::whereNotNull('order_item_id')->count());
        $this->assertSame(0, DigitalKey::whereNotNull('reserved_until')->count());
    }

    public function test_get_expires_without_extending_and_restores_stock_once(): void
    {
        $offer = $this->offer(1);
        $user = User::factory()->create();
        $order = $this->create($user, $offer);
        $this->expire($order);
        foreach (range(1, 2) as $n) {
            $this->assertSame('expired', $this->service()->get($user, $order->id)->status);
            $this->assertSame(1, $offer->fresh()->quantity);
        }
    }

    public function test_new_checkout_reclaims_old_reservation_without_old_buyer_returning(): void
    {
        $offer = $this->offer(1);
        $old = $this->create(User::factory()->create(), $offer);
        $keyId = $old->items->sole()->digitalKeys->sole()->id;
        $this->expire($old);
        $new = $this->create(User::factory()->create(), $offer, 1, 'new-buyer');
        $this->assertSame('expired', $old->fresh()->status);
        $this->assertSame($keyId, $new->items->sole()->digitalKeys->sole()->id);
        $this->assertSame(0, $offer->fresh()->quantity);
    }

    public function test_idempotency_uses_normalized_cart_and_rejects_changed_contents(): void
    {
        $user = User::factory()->create();
        $offer = $this->offer(3);
        $order = $this->create($user, $offer);
        $this->assertSame($order->id, $this->create($user, $offer)->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(2, $offer->fresh()->quantity);
        $this->expectException(CheckoutConflictException::class);
        $this->create($user, $offer, 2);
    }

    public function test_database_unique_and_rollback_guard_preserve_idempotency_history(): void
    {
        $user = User::factory()->create();
        $offer = $this->offer(3);
        $first = $this->create($user, $offer, 1, 'first-key');
        $second = $this->create($user, $offer, 1, 'second-key');
        try {
            DB::transaction(fn () => $second->forceFill(['idempotency_key' => $first->idempotency_key])->save());
            $this->fail('Database accepted a duplicate idempotency key.');
        } catch (UniqueConstraintViolationException $exception) {
            $this->assertSame('23505', $exception->getCode());
        }
        $migration = require database_path('migrations/2026_11_13_000004_add_checkout_idempotency_to_orders.php');
        try {
            $migration->down();
            $this->fail('Checkout request history was removed.');
        } catch (RuntimeException) {
            $this->assertSame('first-key', $first->fresh()->idempotency_key);
        }
    }

    public function test_sale_preserves_counter_and_never_releases_paid_or_sold_keys(): void
    {
        $offer = $this->offer(1);
        $user = User::factory()->create();
        $order = $this->create($user, $offer);
        DB::transaction(function () use ($order): void {
            app(InventoryService::class)->confirmSale($order);
            $order->update(['status' => 'paid']);
        });
        $this->assertSame('sold', $order->items->sole()->digitalKeys->sole()->status);
        $this->assertSame(0, $offer->fresh()->quantity);
        $this->assertSame('paid', $this->service()->get($user, $order->id)->status);
        $this->assertSame(0, app(InventoryService::class)->release($order));
        try {
            $this->service()->cancel($user, $order->id);
            $this->fail('Paid order cancelled.');
        } catch (CheckoutConflictException) {
            $this->assertSame('sold', $order->items->sole()->digitalKeys()->sole()->status);
        }
    }

    public function test_expired_reservation_cannot_be_sold(): void
    {
        $order = $this->create(User::factory()->create(), $this->offer());
        $this->expire($order);
        $this->expectException(CheckoutConflictException::class);
        app(InventoryService::class)->confirmSale($order);
    }

    private function api(string $method, string $path, ?User $user = null, array $body = [], string $key = ''): array
    {
        app('auth')->forgetGuards();
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key];
        if ($user) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$user->createToken('checkout-test')->plainTextToken;
        }
        $request = Request::create($path, $method, [], [], [], $server, json_encode($body));
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return [$response->getStatusCode(), json_decode($response->getContent(), true)];
    }

    public function test_api_authentication_ownership_validation_and_safe_resource(): void
    {
        foreach ([['POST', '/api/checkout'], ['GET', '/api/orders/1'], ['POST', '/api/orders/1/cancel']] as [$method, $path]) {
            $this->assertSame(401, $this->api($method, $path)[0]);
        }
        $user = User::factory()->create();
        $other = User::factory()->create();
        $offer = $this->offer(1);
        $this->assertSame(404, $this->api('GET', '/api/orders/9223372036854775808', $user)[0]);
        $this->assertSame(404, $this->api('POST', '/api/orders/9223372036854775808/cancel', $user)[0]);
        $this->assertSame(422, $this->api('POST', '/api/checkout', $user, ['items' => []], 'empty')[0]);
        $this->assertSame(422, $this->api('POST', '/api/checkout', $user, ['items' => [['offer_id' => $offer->id, 'quantity' => 1]]])[0]);
        $payload = ['items' => [['offer_id' => $offer->id, 'quantity' => 1]]];
        [$status, $body] = $this->api('POST', '/api/checkout', $user, $payload, 'api-request');
        $this->assertSame(201, $status);
        $id = $body['data']['id'];
        $this->assertSame($offer->game->name, $body['data']['items'][0]['game']['name']);
        $this->assertSame($offer->seller->name, $body['data']['items'][0]['seller']['name']);
        $this->assertSame([], $body['data']['payments']);
        $this->assertSame(['id', 'status', 'items', 'total_amount', 'currency', 'reserved_until', 'payments'], array_keys($body['data']));
        $this->assertSame(['id', 'offer_id', 'quantity', 'unit_price', 'game', 'seller'], array_keys($body['data']['items'][0]));
        $this->assertSame(404, $this->api('GET', '/api/orders/'.$id, $other)[0]);
        $this->assertSame(404, $this->api('POST', '/api/orders/'.$id.'/cancel', $other)[0]);
        $this->assertSame($id, $this->api('POST', '/api/checkout', $user, $payload, 'api-request')[1]['data']['id']);
        $this->assertSame(409, $this->api('POST', '/api/checkout', $user, ['items' => [['offer_id' => $offer->id, 'quantity' => 2]]], 'api-request')[0]);
        $this->assertSame(409, $this->api('POST', '/api/checkout', $user, $payload, 'other-request')[0]);
        $this->assertSame(200, $this->api('POST', '/api/orders/'.$id.'/cancel', $user)[0]);
        $this->assertSame(1, $offer->fresh()->quantity);
    }

    /** Two real PHP processes, independent connections, both observed waiting on locks. */
    private function race(string $actionA, User $userA, array $payloadA, string $actionB, User $userB, array $payloadB, string $table, int $id): array
    {
        config(['database.connections.checkout_gate' => config('database.connections.pgsql')]);
        $gate = DB::connection('checkout_gate');
        $gate->beginTransaction();
        $gate->table($table)->where('id', $id)->lockForUpdate()->first();
        $tag = 'checkout-race-'.bin2hex(random_bytes(5));
        $children = [];
        try {
            foreach ([[$actionA, $userA, $payloadA], [$actionB, $userB, $payloadB]] as [$action, $user, $payload]) {
                $process = proc_open([PHP_BINARY, __DIR__.'/checkout-worker.php', $action, (string) $user->id, json_encode($payload), $tag],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/tmp/checkout-worker-errors.log', 'a']], $pipes);
                $this->assertIsResource($process);
                stream_set_timeout($pipes[1], 10);
                $this->assertSame("READY\n", fgets($pipes[1]));
                $children[] = [$process, $pipes];
            }
            foreach ($children as [, $pipes]) {
                fwrite($pipes[0], "GO\n");
                fclose($pipes[0]);
            }
            $bothWaiting = false;
            for ($attempt = 0; $attempt < 250; $attempt++) {
                $n = DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'", [$tag])->n;
                if ($n === 2) {
                    $bothWaiting = true;
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($bothWaiting, 'Both independent checkout connections must contend on real PostgreSQL locks.');
        } finally {
            $gate->rollBack();
            DB::purge('checkout_gate');
        }
        $results = [];
        foreach ($children as [$process, $pipes]) {
            $results[] = json_decode(stream_get_contents($pipes[1]), true);
            fclose($pipes[1]);
            $this->assertSame(0, proc_close($process));
        }

        return $results;
    }

    public function test_concurrent_buyers_compete_for_last_key_without_negative_stock(): void
    {
        $offer = $this->offer(1);
        $payload = ['items' => [['offer_id' => $offer->id, 'quantity' => 1]], 'key' => 'last-key'];
        $results = $this->race('create', User::factory()->create(), $payload, 'create', User::factory()->create(), $payload, 'offers', $offer->id);
        $codes = array_column($results, 'status');
        sort($codes);
        $this->assertSame([201, 409], $codes);
        $this->assertSame(1, Order::count());
        $this->assertSame(0, $offer->fresh()->quantity);
        $this->assertSame(1, DigitalKey::where('status', 'reserved')->count());
    }

    public function test_concurrent_same_idempotency_key_creates_one_order(): void
    {
        $offer = $this->offer(2);
        $user = User::factory()->create();
        $payload = ['items' => [['offer_id' => $offer->id, 'quantity' => 1]], 'key' => 'same-key'];
        $results = $this->race('create', $user, $payload, 'create', $user, $payload, 'offers', $offer->id);
        $this->assertSame([201, 201], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, Order::count());
        $this->assertSame(1, $offer->fresh()->quantity);
    }

    public function test_concurrent_cancel_and_expiration_restore_stock_only_once(): void
    {
        $offer = $this->offer(1);
        $user = User::factory()->create();
        $order = $this->create($user, $offer);
        $this->expire($order);
        $results = $this->race('cancel', $user, ['id' => $order->id], 'get', $user, ['id' => $order->id], 'orders', $order->id);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertSame(1, $offer->fresh()->quantity);
        $this->assertSame(0, DigitalKey::where('status', 'reserved')->count());
    }
}
