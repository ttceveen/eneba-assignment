<?php

namespace Tests\Postgres;

use App\Exceptions\CheckoutConflictException;
use App\Models\DigitalKey;
use App\Models\Game;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Seller;
use App\Models\User;
use App\Payments\FakeCardGateway;
use App\Payments\PaymentGatewayInterface;
use App\Payments\PaymentResult;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentServiceTest extends TestCase
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
        // Real committed fixtures allow provider calls to run at transaction level zero.
        config(['payment.demo_enabled' => true, 'payment.gateways' => ['fake_card' => FakeCardGateway::class],
            'payment.simulation_gateways' => ['fake_card']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app()->instance('env', 'testing');
        foreach (['personal_access_tokens', 'digital_keys', 'payments', 'order_items', 'orders', 'offers', 'game_popularities', 'games', 'sellers', 'users'] as $table) {
            DB::table($table)->delete();
        }
    }

    private function service(): PaymentService
    {
        return app(PaymentService::class);
    }

    private function order(int $quantity = 2): array
    {
        $user = User::factory()->create();
        $offer = $this->offer(3, '12.34');
        $order = app(CheckoutService::class)->create($user, [['offer_id' => $offer->id, 'quantity' => $quantity]], 'order-fixture');

        return [$user, $offer, $order];
    }

    private function payment(User $user, Order $order, string $key = 'payment-fixture'): Payment
    {
        return $this->service()->create($user, $order->id, 'fake_card', $key);
    }

    private function expire(Order $order): void
    {
        $order->update(['reserved_until' => now()->subMinute()]);
        DB::table('digital_keys')->whereIn('order_item_id', $order->items()->pluck('id'))
            ->where('status', 'reserved')->update(['reserved_until' => $order->reserved_until]);
    }

    public function test_creation_uses_order_amount_currency_and_provider_identity(): void
    {
        [$user, , $order] = $this->order();
        $payment = $this->payment($user, $order);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('24.68', $payment->amount);
        $this->assertSame('EUR', $payment->currency);
        $this->assertSame('fake_card_'.$payment->id, $payment->external_id);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame(0, DB::table('game_popularities')->sum('purchase_count'));
    }

    public function test_success_sells_exact_assigned_keys_without_changing_counter(): void
    {
        [$user, $offer, $order] = $this->order();
        $ids = $order->items->sole()->digitalKeys()->orderBy('id')->pluck('id')->all();
        $payment = $this->payment($user, $order);
        $paid = $this->service()->simulate($user, $payment->id, 'success');
        $this->assertSame('succeeded', $paid->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(1, $offer->fresh()->quantity);
        $this->assertSame($ids, DigitalKey::where('status', 'sold')->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, DigitalKey::where('status', 'sold')->whereNotNull('reserved_until')->count());
        $this->assertSame(2, (int) DB::table('game_popularities')->sum('purchase_count'));
    }

    public function test_failed_attempt_preserves_reservation_and_deadline(): void
    {
        [$user, $offer, $order] = $this->order();
        $deadline = $order->reserved_until->toISOString();
        $payment = $this->payment($user, $order);
        $this->assertSame('failed', $this->service()->simulate($user, $payment->id, 'failure')->status);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame($deadline, $order->fresh()->reserved_until->toISOString());
        $this->assertSame(2, DigitalKey::where('status', 'reserved')->count());
        $this->assertSame(1, $offer->fresh()->quantity);
        $this->assertSame(0, (int) DB::table('game_popularities')->sum('purchase_count'));
    }

    public function test_multiple_failed_attempts_then_one_success_keep_original_deadline(): void
    {
        [$user, , $order] = $this->order();
        $deadline = $order->reserved_until->toISOString();
        foreach (range(1, 2) as $n) {
            $attempt = $this->payment($user, $order, 'retry-'.$n);
            $this->service()->simulate($user, $attempt->id, 'failure');
        }
        $last = $this->payment($user, $order, 'retry-success');
        $this->service()->simulate($user, $last->id, 'success');
        $this->assertSame(3, $order->payments()->count());
        $this->assertSame(1, $order->payments()->where('status', 'succeeded')->count());
        $this->assertSame($deadline, $order->fresh()->reserved_until->toISOString());
    }

    public function test_statistics_aggregate_units_of_same_game_across_sellers_once(): void
    {
        [$user, $first, $unused] = $this->order(1);
        app(CheckoutService::class)->cancel($user, $unused->id);
        $second = Offer::create(['seller_id' => Seller::create(['name' => 'Another fixture seller'])->id,
            'game_id' => $first->game_id, 'price' => '2.00', 'quantity' => 2]);
        foreach (range(1, 2) as $n) {
            $second->digitalKeys()->create(['key_value' => 'PAYMENT-SECOND-'.$second->id.'-'.$n]);
        }
        DB::table('game_popularities')->where('game_id', $first->game_id)->update(['purchase_count' => 7]);
        $order = app(CheckoutService::class)->create($user, [['offer_id' => $second->id, 'quantity' => 2],
            ['offer_id' => $first->id, 'quantity' => 1]], 'same-game');
        $payment = $this->payment($user, $order);
        $this->service()->simulate($user, $payment->id, 'success');
        $this->service()->simulate($user, $payment->id, 'success');
        $this->assertSame(10, (int) DB::table('game_popularities')->where('game_id', $first->game_id)->value('purchase_count'));
    }

    public function test_gateway_transport_failure_is_sanitized_and_resumes_same_attempt(): void
    {
        [$user, $offer, $order] = $this->order();
        config(['payment.gateways.unavailable' => UnavailableGateway::class]);
        try {
            $this->service()->create($user, $order->id, 'unavailable', 'transport');
            $this->fail('Provider error was ignored.');
        } catch (HttpException $exception) {
            $this->assertSame(502, $exception->getStatusCode());
            $this->assertFalse(str_contains($exception->getMessage(), 'sensitive-fixture'));
            $this->assertNull($exception->getPrevious());
        }
        $this->assertSame('pending', $order->payments()->sole()->status);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame(1, $offer->fresh()->quantity);
        config(['payment.gateways.unavailable' => TrustedAsyncGateway::class]);
        $resumed = $this->service()->create($user, $order->id, 'unavailable', 'transport');
        $this->assertSame('pending', $resumed->status);
        $this->assertSame(1, $order->payments()->count());
        $this->assertNotNull($resumed->external_id);
    }

    public function test_terminal_outcome_is_idempotent_even_for_opposite_simulation(): void
    {
        [$user, , $order] = $this->order();
        $payment = $this->payment($user, $order);
        $this->service()->simulate($user, $payment->id, 'success');
        foreach (['success', 'failure'] as $result) {
            $this->assertSame('succeeded', $this->service()->simulate($user, $payment->id, $result)->status);
        }
        $this->assertSame(2, (int) DB::table('game_popularities')->sum('purchase_count'));
        $this->assertSame(2, DigitalKey::where('status', 'sold')->count());
    }

    public function test_failed_attempt_cannot_later_be_changed_to_success(): void
    {
        [$user, , $order] = $this->order();
        $payment = $this->payment($user, $order);
        $this->service()->simulate($user, $payment->id, 'failure');
        $this->assertSame('failed', $this->service()->simulate($user, $payment->id, 'success')->status);
        $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
    }

    public function test_creation_idempotency_and_pending_attempt_conflict(): void
    {
        [$user, , $order] = $this->order();
        $payment = $this->payment($user, $order);
        $this->assertSame($payment->id, $this->payment($user, $order)->id);
        try {
            $this->payment($user, $order, 'another-key');
            $this->fail('Two pending attempts accepted.');
        } catch (CheckoutConflictException) {
            $this->assertSame(1, Payment::count());
        }
        config(['payment.gateways.other' => TrustedAsyncGateway::class]);
        $this->expectException(CheckoutConflictException::class);
        $this->service()->create($user, $order->id, 'other', 'payment-fixture');
    }

    public function test_paid_cancelled_and_expired_orders_reject_new_attempts(): void
    {
        foreach (['paid', 'cancelled', 'expired'] as $state) {
            [$user, , $order] = $this->order();
            if ($state === 'paid') {
                $attempt = $this->payment($user, $order);
                $this->service()->simulate($user, $attempt->id, 'success');
            } elseif ($state === 'cancelled') {
                app(CheckoutService::class)->cancel($user, $order->id);
            } else {
                $this->expire($order);
            }
            $before = Payment::count();
            try {
                $this->payment($user, $order, 'refused-'.$state);
                $this->fail('Closed order accepted payment.');
            } catch (CheckoutConflictException) {
                $this->assertSame($before, Payment::count());
                $this->assertSame($state, $order->fresh()->status);
            }
        }
    }

    public function test_late_success_commits_expiration_and_release_without_succeeded_payment(): void
    {
        [$user, $offer, $order] = $this->order();
        $payment = $this->payment($user, $order);
        $this->expire($order);
        foreach (range(1, 2) as $n) {
            try {
                $this->service()->simulate($user, $payment->id, 'success');
                $this->fail('Late payment sold keys.');
            } catch (CheckoutConflictException) {
                $this->assertSame('expired', $order->fresh()->status);
                $this->assertSame('cancelled', $payment->fresh()->status);
                $this->assertSame('late_success', $payment->fresh()->error_code);
                $this->assertSame(3, $offer->fresh()->quantity);
                $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
                $this->assertSame(0, (int) DB::table('game_popularities')->sum('purchase_count'));
            }
        }
    }

    public function test_completion_error_rolls_back_keys_order_payment_and_statistics(): void
    {
        [$user, $offer, $order] = $this->order();
        $payment = $this->payment($user, $order);
        DB::table('game_popularities')->where('game_id', $offer->game_id)->delete();
        try {
            $this->service()->simulate($user, $payment->id, 'success');
            $this->fail('Broken completion succeeded.');
        } catch (CheckoutConflictException) {
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame('pending_payment', $order->fresh()->status);
            $this->assertSame(2, DigitalKey::where('status', 'reserved')->count());
            $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
            $this->assertSame(1, $offer->fresh()->quantity);
        }
    }

    public function test_expiration_during_statistics_write_rolls_back_sale_before_release(): void
    {
        [$user, $offer, $order] = $this->order();
        $payment = $this->payment($user, $order);
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $original);
        $deadline = $order->reserved_until->copy()->addSecond();
        $connection->getEventDispatcher()->listen(QueryExecuted::class,
            function ($event) use ($deadline): void {
                if (str_starts_with($event->sql, 'update') && str_contains($event->sql, 'game_popularities')) {
                    Carbon::setTestNow($deadline);
                }
            });
        try {
            $this->service()->simulate($user, $payment->id, 'success');
            $this->fail('Sale committed after deadline.');
        } catch (CheckoutConflictException) {
            $this->assertSame('expired', $order->fresh()->status);
            $this->assertSame('cancelled', $payment->fresh()->status);
            $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
            $this->assertSame(0, (int) DB::table('game_popularities')->sum('purchase_count'));
            $this->assertSame(3, $offer->fresh()->quantity);
        } finally {
            $connection->setEventDispatcher($original);
        }
    }

    public function test_database_partial_uniques_block_two_pending_and_two_successful(): void
    {
        [$user, , $order] = $this->order();
        $payment = $this->payment($user, $order);
        foreach (['pending', 'succeeded'] as $status) {
            if ($status === 'succeeded') {
                $this->service()->simulate($user, $payment->id, 'success');
            }
            try {
                DB::transaction(fn () => $order->payments()->create(['provider' => 'fixture', 'status' => $status, 'amount' => '24.68', 'currency' => 'EUR']));
                $this->fail('Database unique was bypassed.');
            } catch (UniqueConstraintViolationException $exception) {
                $this->assertSame('23505', $exception->getCode());
            }
        }
    }

    public function test_gateway_result_and_calls_support_async_provider_outside_transaction(): void
    {
        [$user, , $order] = $this->order();
        config(['payment.gateways.trusted_async' => TrustedAsyncGateway::class]);
        $payment = $this->service()->create($user, $order->id, 'trusted_async', 'async');
        $this->assertSame('pending', $payment->status);
        $this->assertSame('https://provider.example/pay', $payment->redirect_url);
        $this->assertSame($payment->id, $this->service()->create($user, $order->id, 'trusted_async', 'async')->id);
        $this->assertSame(404, $this->api('POST', '/api/payments/'.$payment->id.'/simulate', $user, ['result' => 'success'])[0]);
    }

    public function test_fake_gateway_requires_explicit_demo_flag_and_allowed_environment(): void
    {
        [$user, , $order] = $this->order();
        $payment = $this->payment($user, $order);
        config(['payment.demo_enabled' => false]);
        $before = Payment::count();
        $this->assertSame(403, $this->api('POST', '/api/orders/'.$order->id.'/payments', $user, ['provider' => 'fake_card'], 'disabled')[0]);
        $this->assertSame($before, Payment::count());
        $this->assertSame(403, $this->api('POST', '/api/payments/'.$payment->id.'/simulate', $user, ['result' => 'success'])[0]);
        config(['payment.demo_enabled' => true]);
        app()->instance('env', 'production');
        $this->assertSame(403, $this->api('POST', '/api/payments/'.$payment->id.'/simulate', $user, ['result' => 'success'])[0]);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
    }

    public function test_api_ownership_validation_and_key_delivery_without_cache_or_other_keys(): void
    {
        foreach ([['POST', '/api/orders/1/payments'], ['POST', '/api/payments/1/simulate'], ['GET', '/api/orders/1/keys']] as [$method, $path]) {
            $this->assertSame(401, $this->api($method, $path)[0]);
        }
        [$user, , $order] = $this->order();
        [$other, , $otherOrder] = $this->order(1);
        $this->assertSame(404, $this->api('POST', '/api/orders/'.$order->id.'/payments', $other, ['provider' => 'fake_card'], 'foreign')[0]);
        $this->assertSame(409, $this->api('GET', '/api/orders/'.$order->id.'/keys', $user)[0]);
        $this->assertSame(422, $this->api('POST', '/api/orders/'.$order->id.'/payments', $user, ['provider' => 'fake_card'])[0]);
        $this->assertSame(422, $this->api('POST', '/api/orders/'.$order->id.'/payments', $user, ['provider' => 'App\\Payments\\FakeCardGateway'], 'class')[0]);
        $this->assertSame(422, $this->api('POST', '/api/orders/'.$order->id.'/payments', $user, ['provider' => 'fake_card', 'amount' => '0.01'], 'amount')[0]);
        [$code, $body] = $this->api('POST', '/api/orders/'.$order->id.'/payments', $user, ['provider' => 'fake_card'], 'api-payment');
        $this->assertSame(201, $code);
        $id = $body['data']['id'];
        $recovered = $this->api('GET', '/api/orders/'.$order->id, $user)[1]['data']['payments'];
        $this->assertSame($id, $recovered[0]['id']);
        $this->assertSame('api-payment', $recovered[0]['idempotency_key']);
        $this->assertSame('pending', $recovered[0]['status']);
        $this->assertSame($id, $this->api('POST', '/api/orders/'.$order->id.'/payments', $user, ['provider' => 'fake_card'], 'api-payment')[1]['data']['id']);
        $this->assertSame(404, $this->api('POST', '/api/payments/'.$id.'/simulate', $other, ['result' => 'success'])[0]);
        $this->assertSame(200, $this->api('POST', '/api/payments/'.$id.'/simulate', $user, ['result' => 'success'])[0]);
        $this->assertSame(404, $this->api('GET', '/api/orders/'.$order->id.'/keys', $other)[0]);
        [$status, $data, $headers] = $this->api('GET', '/api/orders/'.$order->id.'/keys', $user);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('no-store', $headers['cache-control'][0]);
        $this->assertSame('no-cache', $headers['pragma'][0]);
        $this->assertSame($order->id, $data['data']['order_id']);
        $keys = $data['data']['items'][0]['keys'];
        $expected = $order->items->sole()->digitalKeys()->orderBy('id')->get();
        $this->assertCount(2, $keys);
        foreach ($expected as $i => $key) {
            $this->assertTrue(hash_equals($key->key_value, $keys[$i]['key_value']));
        }
        $this->assertTrue($data === $this->api('GET', '/api/orders/'.$order->id.'/keys', $user)[1]);
        $this->assertSame(409, $this->api('GET', '/api/orders/'.$otherOrder->id.'/keys', $other)[0]);
        $normal = $this->api('GET', '/api/orders/'.$order->id, $user)[1];
        $this->assertFalse(str_contains(json_encode($normal), 'key_value'));
    }

    public function test_keys_endpoint_refuses_cancelled_and_expired_orders_without_mutating_them(): void
    {
        foreach (['cancelled', 'expired'] as $status) {
            [$user, , $order] = $this->order();
            $order->update(['status' => $status]);
            $this->assertSame(409, $this->api('GET', '/api/orders/'.$order->id.'/keys', $user)[0]);
            $this->assertSame($status, $order->fresh()->status);
        }
    }

    public function test_concurrent_confirmations_sell_and_increment_statistics_once(): void
    {
        [$user, $offer, $order] = $this->order();
        $payment = $this->payment($user, $order);
        $results = $this->race('simulate', $user, ['id' => $payment->id], 'simulate', $user, ['id' => $payment->id], 'orders', $order->id);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(2, DigitalKey::where('status', 'sold')->count());
        $this->assertSame(2, (int) DB::table('game_popularities')->sum('purchase_count'));
        $this->assertSame(1, $offer->fresh()->quantity);
    }

    public function test_concurrent_creations_cannot_make_two_active_payments(): void
    {
        [$user, , $order] = $this->order();
        $results = $this->race('pay', $user, ['id' => $order->id, 'key' => 'first'], 'pay', $user, ['id' => $order->id, 'key' => 'second'], 'orders', $order->id);
        $codes = array_column($results, 'status');
        sort($codes);
        $this->assertSame([201, 409], $codes);
        $this->assertSame(1, $order->payments()->where('status', 'pending')->count());
    }

    public function test_concurrent_same_payment_key_returns_one_attempt(): void
    {
        [$user, , $order] = $this->order();
        $results = $this->race('pay', $user, ['id' => $order->id, 'key' => 'same'], 'pay', $user, ['id' => $order->id, 'key' => 'same'], 'orders', $order->id);
        $this->assertSame([201, 201], array_column($results, 'status'));
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, Payment::count());
    }

    public function test_concurrent_cancel_and_success_have_one_consistent_outcome(): void
    {
        [$user, $offer, $order] = $this->order();
        $payment = $this->payment($user, $order);
        $results = $this->race('simulate', $user, ['id' => $payment->id], 'cancel', $user, ['id' => $order->id], 'orders', $order->id);
        $codes = array_column($results, 'status');
        sort($codes);
        $this->assertSame([200, 409], $codes);
        $paid = $order->fresh()->status === 'paid';
        $this->assertSame($paid ? 'succeeded' : 'cancelled', $payment->fresh()->status);
        $this->assertSame($paid ? 2 : 0, DigitalKey::where('status', 'sold')->count());
        $this->assertSame($paid ? 1 : 3, $offer->fresh()->quantity);
        $this->assertSame($paid ? 2 : 0, (int) DB::table('game_popularities')->sum('purchase_count'));
    }

    public function test_concurrent_expiration_and_success_cannot_sell_expired_keys(): void
    {
        [$user, $offer, $order] = $this->order();
        $payment = $this->payment($user, $order);
        $this->expire($order);
        $results = $this->race('simulate', $user, ['id' => $payment->id], 'get', $user, ['id' => $order->id], 'orders', $order->id);
        $codes = array_column($results, 'status');
        sort($codes);
        $this->assertSame([200, 409], $codes);
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertSame('cancelled', $payment->fresh()->status);
        $this->assertSame(3, $offer->fresh()->quantity);
        $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
        $this->assertSame(0, (int) DB::table('game_popularities')->sum('purchase_count'));
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

        return [$response->getStatusCode(), json_decode($response->getContent(), true), $response->headers->all()];
    }

    /** Two real PHP processes, independent connections, both observed waiting on locks. */
    private function race(string $actionA, User $userA, array $payloadA, string $actionB, User $userB, array $payloadB, string $table, int $id): array
    {
        config(['database.connections.checkout_gate' => config('database.connections.pgsql')]);
        $gate = DB::connection('checkout_gate');
        $gate->beginTransaction();
        $gate->table($table)->where('id', $id)->lockForUpdate()->first();
        $tag = 'payment-race-'.bin2hex(random_bytes(5));
        $children = [];
        try {
            foreach ([[$actionA, $userA, $payloadA], [$actionB, $userB, $payloadB]] as [$action, $user, $payload]) {
                $process = proc_open([PHP_BINARY, __DIR__.'/payment-worker.php', $action, (string) $user->id, json_encode($payload), $tag],
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
}

// Fixture adapter verifies the contract without implementing a real provider.
class TrustedAsyncGateway implements PaymentGatewayInterface
{
    public function create(Payment $payment): PaymentResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Gateway was invoked inside a transaction.');
        }

        return new PaymentResult('pending', 'trusted_'.$payment->id, 'https://provider.example/pay');
    }

    public function confirm(Payment $payment, array $context = []): PaymentResult
    {
        throw new RuntimeException('Client simulation must not call a real provider.');
    }

    public function supportsSimulation(): bool
    {
        return false;
    }
}

class UnavailableGateway implements PaymentGatewayInterface
{
    public function create(Payment $payment): PaymentResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('Gateway called inside transaction.');
        }
        throw new RuntimeException('sensitive-fixture-provider-details');
    }

    public function confirm(Payment $payment, array $context = []): PaymentResult
    {
        throw new RuntimeException('Not implemented by this test adapter.');
    }

    public function supportsSimulation(): bool
    {
        return false;
    }
}
