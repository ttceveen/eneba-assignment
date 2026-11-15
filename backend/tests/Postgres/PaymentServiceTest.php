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
use App\Payments\PaymentGatewayRegistry;
use App\Payments\PaymentResult;
use App\Payments\PayPalGateway;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_paypal_registry_resolves_real_gateway_without_http_or_payment_attempts(): void
    {
        $this->configurePayPalHttp();
        $this->assertInstanceOf(PayPalGateway::class, app(PaymentGatewayRegistry::class)->get('paypal'));
        $this->assertSame(0, Payment::count());
        Http::assertNothingSent();
    }

    public function test_paypal_creation_persists_external_order_and_pending_status_without_delivering_keys(): void
    {
        $this->configurePayPalHttp();
        [$user, $offer, $order] = $this->order();
        $deadline = $order->reserved_until->toISOString();
        $assignedIds = $order->items->sole()->digitalKeys()->orderBy('id')->pluck('id')->all();
        $payment = $this->service()->create($user, $order->id, 'paypal', 'paypal-create-test');
        $stored = Payment::findOrFail($payment->id);
        $this->assertSame('paypal', $stored->provider);
        $this->assertSame('5O190127TN364715T', $stored->external_id);
        $this->assertSame('https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T', $stored->redirect_url);
        $this->assertSame('pending', $stored->status);
        $this->assertSame('24.68', $stored->amount);
        $this->assertSame('EUR', $stored->currency);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame($deadline, $order->fresh()->reserved_until->toISOString());
        $this->assertSame($assignedIds, $order->items->sole()->digitalKeys()->where('status', 'reserved')->orderBy('id')->pluck('id')->all());
        $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
        $this->assertSame(1, $offer->fresh()->quantity);
        $this->assertSame(0, (int) DB::table('game_popularities')->sum('purchase_count'));
        try {
            $this->service()->keys($user, $order->id);
            $this->fail('Unpaid keys were delivered.');
        } catch (CheckoutConflictException) {
            $this->assertSame('pending', $stored->fresh()->status);
        }
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer paypal-integration-token')
            && $request->hasHeader('PayPal-Request-Id', 'paypal-create-'.$payment->id)
            && $request['intent'] === 'CAPTURE'
            && $request['purchase_units'][0]['amount'] === ['currency_code' => 'EUR', 'value' => '24.68']
            && $request['payment_source']['paypal']['experience_context'] === [
                'return_url' => 'http://localhost:5173/paypal-return',
                'cancel_url' => 'http://localhost:5173/paypal-cancel', 'shipping_preference' => 'NO_SHIPPING',
            ]);
        Http::assertSentCount(2);
    }

    public function test_paypal_same_key_reuses_payment_without_repeating_provider_creation(): void
    {
        $this->configurePayPalHttp();
        [$user, , $order] = $this->order();
        $payment = $this->service()->create($user, $order->id, 'paypal', 'same-paypal-key');
        $again = $this->service()->create($user, $order->id, 'paypal', 'same-paypal-key');
        $this->assertSame($payment->id, $again->id);
        $this->assertSame('5O190127TN364715T', $again->external_id);
        $this->assertSame(1, $order->payments()->count());
        Http::assertSentCount(2);
        try {
            $this->service()->create($user, $order->id, 'paypal', 'different-paypal-key');
            $this->fail('A second active attempt was accepted.');
        } catch (CheckoutConflictException) {
            $this->assertSame(1, $order->payments()->count());
        }
        Http::assertSentCount(2);
    }

    public function test_paypal_api_creation_error_keeps_pending_attempt_and_reserved_keys(): void
    {
        $this->configurePayPalHttp(Http::response(['message' => 'paypal-integration-secret paypal-integration-token'], 503));
        [$user, , $order] = $this->order();
        $deadline = $order->reserved_until->toISOString();
        $this->assertPayPalCreationError($user, $order, 'paypal-error');
        $payment = $order->payments()->sole();
        $this->assertSame('pending', $payment->status);
        $this->assertNull($payment->external_id);
        $this->assertNull($payment->redirect_url);
        $this->assertSame('gateway_unavailable', $payment->error_code);
        $this->assertStringNotContainsString('paypal-integration-secret', $payment->error_message);
        $this->assertStringNotContainsString('paypal-integration-token', $payment->error_message);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame($deadline, $order->fresh()->reserved_until->toISOString());
        $this->assertSame(2, DigitalKey::where('status', 'reserved')->count());
        $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
        Http::assertSentCount(2);
    }

    public function test_paypal_timeout_retry_resumes_same_database_attempt_and_provider_request_id(): void
    {
        $ids = [];
        $this->configurePayPalHttp(function (ClientRequest $request) use (&$ids) {
            $ids[] = $request->header('PayPal-Request-Id')[0];
            if (count($ids) === 1) {
                throw new ConnectionException('cURL error 28: paypal-integration-secret paypal-integration-token');
            }

            return Http::response($this->payPalOrderResponse(), 201);
        });
        [$user, , $order] = $this->order();
        $this->assertPayPalCreationError($user, $order, 'lost-paypal-response');
        $attemptId = $order->payments()->sole()->id;
        $retried = $this->service()->create($user, $order->id, 'paypal', 'lost-paypal-response');
        $this->assertSame($attemptId, $retried->id);
        $this->assertSame(['paypal-create-'.$attemptId, 'paypal-create-'.$attemptId], $ids);
        $this->assertSame('5O190127TN364715T', $retried->external_id);
        $this->assertSame('pending', $retried->status);
        $this->assertNull($retried->error_code);
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
    }

    public function test_malformed_paypal_order_response_does_not_persist_fake_success(): void
    {
        $this->configurePayPalHttp(Http::response(['id' => 'ORDER123', 'links' => []], 201));
        [$user, , $order] = $this->order();
        $this->assertPayPalCreationError($user, $order, 'malformed-paypal-response');
        $this->assertSame('pending', $order->payments()->sole()->status);
        $this->assertNull($order->payments()->sole()->external_id);
        $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
    }

    public function test_fake_card_still_completes_with_paypal_registered_and_enabled(): void
    {
        $this->configurePayPalHttp();
        [$user, , $order] = $this->order();
        $payment = $this->payment($user, $order);
        $this->assertSame('fake_card_'.$payment->id, $payment->external_id);
        $this->assertSame('succeeded', $this->service()->simulate($user, $payment->id, 'success')->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(2, DigitalKey::where('status', 'sold')->count());
        $this->assertCount(2, $this->service()->keys($user, $order->id)['items'][0]['keys']);
        Http::assertNothingSent();
    }

    public function test_disabled_paypal_is_rejected_before_creating_a_database_attempt(): void
    {
        $this->configurePayPalHttp();
        config(['payment.paypal.enabled' => false]);
        [$user, , $order] = $this->order();
        try {
            $this->service()->create($user, $order->id, 'paypal', 'disabled-paypal');
            $this->fail('Disabled PayPal created a payment.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, $order->payments()->count());
        Http::assertNothingSent();
    }

    public function test_protected_paypal_result_completes_original_sale_after_deadline_once(): void
    {
        [$user, $offer, $order] = $this->order();
        $payment = $order->payments()->create(['provider' => 'paypal', 'status' => 'pending', 'amount' => $order->total_amount,
            'currency' => 'EUR', 'external_id' => 'ORDER123']);
        $payment->forceFill(['confirmation_started_at' => now()])->save();
        $ids = $order->items->sole()->digitalKeys()->orderBy('id')->pluck('id')->all();
        Carbon::setTestNow($order->reserved_until->copy()->addSecond());
        // Internal result boundary only: no confirm endpoint, capture or provider HTTP is introduced.
        $result = new PaymentResult('succeeded', 'ORDER123', captureId: 'CAPTURE123');
        $this->assertSame('succeeded', $this->service()->applyResult($payment->id, $result)->status);
        $this->assertSame('succeeded', $this->service()->applyResult($payment->id, $result)->status);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame($ids, $order->items->sole()->digitalKeys()->where('status', 'sold')->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, $offer->fresh()->quantity);
        $this->assertSame(2, (int) DB::table('game_popularities')->sum('purchase_count'));
        $this->assertCount(2, $this->service()->keys($user, $order->id)['items'][0]['keys']);
    }

    public function test_protected_paypal_completion_failure_preserves_reservation_after_deadline(): void
    {
        [, $offer, $order] = $this->order();
        $payment = $order->payments()->create(['provider' => 'paypal', 'status' => 'pending', 'amount' => $order->total_amount,
            'currency' => 'EUR', 'external_id' => 'ORDER123']);
        $payment->forceFill(['confirmation_started_at' => now()])->save();
        DB::table('game_popularities')->where('game_id', $offer->game_id)->delete();
        Carbon::setTestNow($order->reserved_until->copy()->addSecond());
        try {
            $this->service()->applyResult($payment->id, new PaymentResult('succeeded', 'ORDER123'));
            $this->fail('Broken completion succeeded.');
        } catch (CheckoutConflictException) {
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame('pending_payment', $order->fresh()->status);
            $this->assertSame(2, DigitalKey::where('status', 'reserved')->count());
            $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
            $this->assertSame(1, $offer->fresh()->quantity);
        }
    }

    public function test_confirm_paypal_success_marks_paid_and_sells_original_keys_once(): void
    {
        [$user, $offer, $order, $payment] = $this->confirmationFixture();
        $keys = $order->items->sole()->digitalKeys()->orderBy('id')->pluck('id')->all();
        $this->fakeConfirmation($payment);
        $confirmed = $this->service()->confirm($user, $payment->external_id);
        $this->assertSame('succeeded', $confirmed->status);
        $this->assertSame('CAPTURE123', $confirmed->capture_id);
        $this->assertSame($payment->external_id, $confirmed->external_id);
        $this->assertNotNull($confirmed->confirmation_started_at);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame($keys, $order->items->sole()->digitalKeys()->where('status', 'sold')->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, $offer->fresh()->quantity);
        $this->assertSame(2, (int) DB::table('game_popularities')->sum('purchase_count'));
        $count = count(Http::recorded());
        $again = $this->service()->confirm($user, $payment->external_id);
        $this->assertSame($confirmed->id, $again->id);
        $this->assertTrue($confirmed->confirmation_started_at->equalTo($again->confirmation_started_at));
        $this->assertSame($count, count(Http::recorded()));
        $this->assertSame(2, (int) DB::table('game_popularities')->sum('purchase_count'));
    }

    public function test_confirm_paypal_pending_capture_saves_identity_but_keeps_keys_reserved(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $this->fakeConfirmation($payment, capture: fn () => Http::response($this->confirmationDetails('COMPLETED', 'PENDING')));
        $result = $this->service()->confirm($user, $payment->external_id);
        $this->assertSame('pending', $result->status);
        $this->assertSame('CAPTURE123', $result->capture_id);
        Carbon::setTestNow($order->reserved_until->copy()->addSecond());
        $this->assertSame('pending_payment', app(CheckoutService::class)->get($user, $order->id)->status);
        $this->assertSame(2, DigitalKey::where('status', 'reserved')->count());
        $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
    }

    public function test_confirm_timeout_preserves_marker_and_retry_recovers_existing_capture_after_deadline(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $gets = 0;
        $captures = 0;
        $this->fakeConfirmation($payment, lookup: function () use (&$gets) {
            return Http::response(++$gets === 1 ? $this->confirmationDetails('APPROVED') : $this->confirmationDetails('COMPLETED', 'COMPLETED'));
        }, capture: function () use (&$captures) {
            $captures++;
            throw new ConnectionException('cURL error 28 sensitive-provider-token');
        });
        try {
            $this->service()->confirm($user, $payment->external_id);
            $this->fail('Timeout was not reported.');
        } catch (HttpException $exception) {
            $this->assertSame(502, $exception->getStatusCode());
            $this->assertSame('The payment provider could not confirm this attempt.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $marker = $payment->fresh()->confirmation_started_at;
        $this->assertNotNull($marker);
        $this->assertSame('pending', $payment->fresh()->status);
        Carbon::setTestNow($order->reserved_until->copy()->addSecond());
        $this->assertSame('pending_payment', app(CheckoutService::class)->get($user, $order->id)->status);
        $this->assertSame('succeeded', $this->service()->confirm($user, $payment->external_id)->status);
        $this->assertTrue($marker->equalTo($payment->fresh()->confirmation_started_at));
        $this->assertSame(1, $captures);
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_confirm_retry_after_local_failure_reuses_saved_capture_and_original_keys(): void
    {
        [$user, $offer, $order, $payment] = $this->confirmationFixture();
        $gets = 0;
        $captures = 0;
        $this->fakeConfirmation($payment, lookup: function () use (&$gets) {
            return Http::response(++$gets === 1 ? $this->confirmationDetails('APPROVED') : $this->confirmationDetails('COMPLETED', 'COMPLETED'));
        }, capture: function () use (&$captures) {
            $captures++;

            return Http::response($this->confirmationDetails('COMPLETED', 'COMPLETED'));
        });
        DB::table('game_popularities')->where('game_id', $offer->game_id)->delete();
        try {
            $this->service()->confirm($user, $payment->external_id);
            $this->fail('Completion with missing statistics succeeded.');
        } catch (CheckoutConflictException) {
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame('CAPTURE123', $payment->fresh()->capture_id);
            $this->assertSame(2, DigitalKey::where('status', 'reserved')->count());
            $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
        }
        DB::table('game_popularities')->insert(['game_id' => $offer->game_id, 'purchase_count' => 0]);
        Carbon::setTestNow($order->reserved_until->copy()->addSecond());
        $this->assertSame('succeeded', $this->service()->confirm($user, $payment->external_id)->status);
        $this->assertSame(1, $captures);
        $this->assertSame(2, (int) DB::table('game_popularities')->sum('purchase_count'));
    }

    public function test_confirm_can_cross_reservation_deadline_during_capture(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $this->fakeConfirmation($payment, capture: function () use ($user, $order) {
            Carbon::setTestNow($order->reserved_until->copy()->addSecond());
            $this->assertSame('pending_payment', app(CheckoutService::class)->get($user, $order->id)->status);
            $this->assertSame(2, DigitalKey::where('status', 'reserved')->count());

            return Http::response($this->confirmationDetails('COMPLETED', 'COMPLETED'));
        });
        $this->assertSame('succeeded', $this->service()->confirm($user, $payment->external_id)->status);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_confirm_foreign_and_unknown_payment_both_return_not_found_without_http(): void
    {
        [$user, , , $payment] = $this->confirmationFixture();
        $other = User::factory()->create();
        $before = count(Http::recorded());
        foreach ([[$other, $payment->external_id], [$user, 'UNKNOWN123']] as [$actor, $externalId]) {
            try {
                $this->service()->confirm($actor, $externalId);
                $this->fail('Unknown payment was confirmed.');
            } catch (ModelNotFoundException) {
                $this->assertNull($payment->fresh()->confirmation_started_at);
            }
        }
        $this->assertSame($before, count(Http::recorded()));
    }

    public function test_confirm_expired_unmarked_order_is_rejected_without_backdating_or_http(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $this->expire($order);
        $before = count(Http::recorded());
        try {
            $this->service()->confirm($user, $payment->external_id);
            $this->fail('Expired order was confirmed.');
        } catch (CheckoutConflictException) {
            $this->assertSame('expired', $order->fresh()->status);
            $this->assertNull($payment->fresh()->confirmation_started_at);
            $this->assertSame(0, DigitalKey::where('status', 'reserved')->count());
        }
        $this->assertSame($before, count(Http::recorded()));
    }

    public function test_confirm_damaged_original_keys_is_rejected_before_marker_and_http(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $order->items->sole()->digitalKeys()->first()->delete();
        $before = count(Http::recorded());
        try {
            $this->service()->confirm($user, $payment->external_id);
            $this->fail('Missing keys were accepted.');
        } catch (CheckoutConflictException) {
            $this->assertNull($payment->fresh()->confirmation_started_at);
        }
        $this->assertSame($before, count(Http::recorded()));
    }

    public function test_confirm_refuses_to_replace_an_existing_capture_id(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $payment->forceFill(['capture_id' => 'ORIGINALCAPTURE'])->save();
        $this->fakeConfirmation($payment);
        try {
            $this->service()->confirm($user, $payment->external_id);
            $this->fail('Capture identity was replaced.');
        } catch (CheckoutConflictException) {
            $this->assertSame('ORIGINALCAPTURE', $payment->fresh()->capture_id);
            $this->assertSame('pending', $payment->fresh()->status);
            $this->assertSame('pending_payment', $order->fresh()->status);
            $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
        }
    }

    public function test_confirm_cannot_reuse_another_payments_capture_id(): void
    {
        [$user, , , $payment] = $this->confirmationFixture();
        [, , $otherOrder] = $this->order(1);
        $otherOrder->payments()->create(['provider' => 'paypal', 'status' => 'failed', 'amount' => $otherOrder->total_amount, 'currency' => 'EUR'])
            ->forceFill(['capture_id' => 'CAPTURE123'])->save();
        $this->fakeConfirmation($payment);
        try {
            $this->service()->confirm($user, $payment->external_id);
            $this->fail('Capture was reused.');
        } catch (CheckoutConflictException $exception) {
            $this->assertSame('The capture is already assigned to another payment.', $exception->getMessage());
            $this->assertNull($payment->fresh()->capture_id);
            $this->assertSame('pending', $payment->fresh()->status);
        }
    }

    public function test_confirm_late_marker_is_not_used_to_bypass_deadline(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $this->expire($order);
        $payment->forceFill(['confirmation_started_at' => now()])->save();
        $before = count(Http::recorded());
        try {
            $this->service()->confirm($user, $payment->external_id);
            $this->fail('Late marker was accepted.');
        } catch (CheckoutConflictException) {
            $this->assertSame($before, count(Http::recorded()));
        }
    }

    public function test_concurrent_paypal_confirmations_sell_and_increment_once(): void
    {
        [$user, $offer, $order, $payment] = $this->confirmationFixture();
        $keys = $order->items->sole()->digitalKeys()->orderBy('id')->pluck('id')->all();
        $payload = ['id' => $payment->id, 'paypal_order_id' => $payment->external_id];
        $results = $this->race('confirm', $user, $payload, 'confirm', $user, $payload, 'orders', $order->id);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame('CAPTURE123', $payment->fresh()->capture_id);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame($keys, $order->items->sole()->digitalKeys()->where('status', 'sold')->orderBy('id')->pluck('id')->all());
        $this->assertSame(2, (int) DB::table('game_popularities')->sum('purchase_count'));
        $this->assertSame(1, $offer->fresh()->quantity);
        $this->assertSame(1, $order->payments()->count());
    }

    public function test_paypal_confirm_api_returns_existing_resource_without_capture_id_or_keys(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $this->fakeConfirmation($payment);
        [$status, $body, $headers] = $this->api('POST', '/api/payments/paypal/confirm', $user, ['paypal_order_id' => $payment->external_id]);
        $this->assertSame(200, $status);
        $this->assertSame('succeeded', $body['data']['status']);
        $this->assertSame($payment->id, $body['data']['id']);
        $this->assertSame($order->id, $body['data']['order_id']);
        $this->assertSame($payment->external_id, $body['data']['external_id']);
        $this->assertArrayNotHasKey('capture_id', $body['data']);
        $this->assertArrayNotHasKey('captureId', $body['data']);
        $this->assertStringNotContainsString('CAPTURE123', json_encode($body));
        $this->assertStringNotContainsString('key_value', json_encode($body));
        $this->assertStringContainsString('no-store', $headers['cache-control'][0]);
        $this->assertSame('CAPTURE123', $payment->fresh()->capture_id);
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertSame(200, $this->api('GET', '/api/orders/'.$order->id, $user)[0]);
        $this->assertSame(200, $this->api('GET', '/api/orders/'.$order->id.'/keys', $user)[0]);
    }

    public function test_paypal_confirm_api_returns_pending_without_key_delivery(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $this->fakeConfirmation($payment, capture: fn () => Http::response($this->confirmationDetails('COMPLETED', 'PENDING')));
        [$status, $body] = $this->api('POST', '/api/payments/paypal/confirm', $user, ['paypal_order_id' => $payment->external_id]);
        $this->assertSame(200, $status);
        $this->assertSame('pending', $body['data']['status']);
        $this->assertSame($order->id, $body['data']['order_id']);
        $this->assertArrayNotHasKey('capture_id', $body['data']);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
        $this->assertSame(409, $this->api('GET', '/api/orders/'.$order->id.'/keys', $user)[0]);
    }

    public function test_paypal_confirm_api_repeat_does_not_repeat_provider_calls_or_issue_keys_again(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $this->fakeConfirmation($payment);
        $first = $this->api('POST', '/api/payments/paypal/confirm', $user, ['paypal_order_id' => $payment->external_id]);
        $count = count(Http::recorded());
        $second = $this->api('POST', '/api/payments/paypal/confirm', $user, ['paypal_order_id' => $payment->external_id]);
        $this->assertSame(200, $first[0]);
        $this->assertSame(200, $second[0]);
        $this->assertSame($first[1], $second[1]);
        $this->assertSame($count, count(Http::recorded()));
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(2, DigitalKey::where('status', 'sold')->count());
        $this->assertSame(2, (int) DB::table('game_popularities')->sum('purchase_count'));
    }

    public function test_paypal_confirm_api_requires_sanctum_authentication_and_active_user(): void
    {
        $this->configurePayPalHttp();
        $this->assertSame(401, $this->api('POST', '/api/payments/paypal/confirm', body: ['paypal_order_id' => 'ORDER123'])[0]);
        $blocked = User::factory()->create();
        $blocked->forceFill(['status' => 'blocked'])->save();
        $this->assertSame(403, $this->api('POST', '/api/payments/paypal/confirm', $blocked, ['paypal_order_id' => 'ORDER123'])[0]);
        Http::assertNothingSent();
    }

    public function test_paypal_confirm_api_foreign_and_unknown_order_ids_both_return_404(): void
    {
        [$owner, , , $payment] = $this->confirmationFixture();
        $other = User::factory()->create();
        $count = count(Http::recorded());
        $foreign = $this->api('POST', '/api/payments/paypal/confirm', $other, ['paypal_order_id' => $payment->external_id]);
        $unknown = $this->api('POST', '/api/payments/paypal/confirm', $owner, ['paypal_order_id' => 'UNKNOWN123']);
        $this->assertSame(404, $foreign[0]);
        $this->assertSame(404, $unknown[0]);
        $this->assertSame($unknown[1]['message'], $foreign[1]['message']);
        $this->assertSame($count, count(Http::recorded()));
        $this->assertNull($payment->fresh()->confirmation_started_at);
    }

    public function test_paypal_confirm_api_validates_id_and_rejects_all_extra_fields(): void
    {
        $this->configurePayPalHttp();
        $user = User::factory()->create();
        $invalid = [[], ['paypal_order_id' => null], ['paypal_order_id' => ''], ['paypal_order_id' => '   '],
            ['paypal_order_id' => 123], ['paypal_order_id' => ['ORDER123']], ['paypal_order_id' => str_repeat('A', 37)],
            ['paypal_order_id' => 'ORDER/123'], ['paypal_order_id' => 'lowercase']];
        foreach (['amount', 'currency', 'status', 'capture_id', 'captureId', 'result', 'provider'] as $field) {
            $invalid[] = ['paypal_order_id' => 'ORDER123', $field => 'untrusted'];
        }
        foreach ($invalid as $body) {
            $this->assertSame(422, $this->api('POST', '/api/payments/paypal/confirm', $user, $body)[0]);
        }
        $this->assertSame(0, Payment::count());
        Http::assertNothingSent();
    }

    public function test_paypal_confirm_api_provider_error_is_safe_and_preserves_pending_attempt(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $this->fakeConfirmation($payment, lookup: fn () => Http::response([
            'message' => 'paypal-integration-secret paypal-integration-token SQLSTATE-sensitive',
        ], 503));
        [$status, $body] = $this->api('POST', '/api/payments/paypal/confirm', $user, ['paypal_order_id' => $payment->external_id]);
        $this->assertSame(502, $status);
        $this->assertSame('The payment provider could not confirm this attempt.', $body['message']);
        foreach (['paypal-integration-secret', 'paypal-integration-token', 'SQLSTATE-sensitive'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, json_encode($body));
        }
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->confirmation_started_at);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
    }

    public function test_paypal_confirm_api_timeout_is_safe_and_does_not_fail_the_payment(): void
    {
        [$user, , , $payment] = $this->confirmationFixture();
        $this->fakeConfirmation($payment, capture: fn () => throw new ConnectionException('sensitive-provider-token'));
        [$status, $body] = $this->api('POST', '/api/payments/paypal/confirm', $user, ['paypal_order_id' => $payment->external_id]);
        $this->assertSame(502, $status);
        $this->assertStringNotContainsString('sensitive-provider-token', json_encode($body));
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->confirmation_started_at);
    }

    public function test_paypal_confirm_api_rejects_expired_or_cancelled_order_without_provider_http(): void
    {
        foreach (['expired', 'cancelled'] as $state) {
            $this->configurePayPalHttp();
            [$user, , $order] = $this->order();
            $payment = $order->payments()->create(['provider' => 'paypal', 'status' => 'pending', 'amount' => $order->total_amount,
                'currency' => 'EUR', 'external_id' => strtoupper($state).'123']);
            if ($state === 'expired') {
                $this->expire($order);
            } else {
                app(CheckoutService::class)->cancel($user, $order->id);
            }
            $this->assertSame(409, $this->api('POST', '/api/payments/paypal/confirm', $user, ['paypal_order_id' => $payment->external_id])[0]);
            $this->assertNull($payment->fresh()->confirmation_started_at);
            Http::assertNothingSent();
        }
    }

    public function test_payer_action_required_checks_preserve_approval_url_without_creating_or_capturing_again(): void
    {
        [$user, , $order, $payment] = $this->confirmationFixture();
        $url = $payment->redirect_url;
        $this->assertNotNull($url);
        $this->fakeConfirmation($payment, lookup: fn () => Http::response($this->confirmationDetails('PAYER_ACTION_REQUIRED')));
        $createsBefore = count(Http::recorded(fn (ClientRequest $request): bool => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'));
        $first = $this->service()->confirm($user, $payment->external_id);
        $this->assertSame('pending', $first->status);
        $this->assertSame($url, $first->redirect_url);
        $marker = $first->confirmation_started_at;
        $again = $this->service()->confirm($user, $payment->external_id);
        $this->assertSame($url, $again->redirect_url);
        $this->assertSame($payment->external_id, $again->external_id);
        $this->assertNull($again->capture_id);
        $this->assertTrue($marker->equalTo($again->confirmation_started_at));
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(0, DigitalKey::where('status', 'sold')->count());
        $creates = $captures = $lookups = 0;
        foreach (Http::recorded() as [$request]) {
            if ($request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders') {
                $creates++;
            }
            if (str_ends_with($request->url(), '/capture')) {
                $captures++;
            }
            if ($request->method() === 'GET') {
                $lookups++;
            }
        }
        $this->assertSame(0, $captures);
        $this->assertSame(2, $lookups);
        $this->assertSame($createsBefore, $creates);
    }

    public function test_pending_result_can_replace_approval_url_without_changing_confirmation_metadata(): void
    {
        [, , , $payment] = $this->confirmationFixture();
        $payment->forceFill(['confirmation_started_at' => now(), 'capture_id' => 'SAVEDCAPTURE'])->save();
        $marker = $payment->fresh()->confirmation_started_at;
        $url = 'https://www.sandbox.paypal.com/checkoutnow?token=REPLACEMENT123';
        $result = $this->service()->applyResult($payment->id, new PaymentResult('pending', $payment->external_id, $url));
        $this->assertSame($url, $result->redirect_url);
        $this->assertSame($payment->external_id, $result->external_id);
        $this->assertSame('SAVEDCAPTURE', $result->capture_id);
        $this->assertTrue($marker->equalTo($result->confirmation_started_at));
        $this->assertSame($url, $this->service()->applyResult($payment->id, new PaymentResult('pending', $payment->external_id))->redirect_url);
    }

    public function test_pending_result_without_any_approval_url_does_not_invent_one(): void
    {
        [, , $order] = $this->order();
        $payment = $order->payments()->create(['provider' => 'paypal', 'status' => 'pending', 'amount' => $order->total_amount,
            'currency' => 'EUR', 'external_id' => 'ORDER123']);
        $this->assertNull($this->service()->applyResult($payment->id, new PaymentResult('pending', 'ORDER123'))->redirect_url);
    }

    public function test_terminal_results_clear_approval_url_and_later_results_cannot_restore_it(): void
    {
        foreach (['succeeded', 'failed', 'cancelled'] as $status) {
            [, , $order] = $this->order();
            $externalId = 'ORDER'.$order->id;
            $payment = $order->payments()->create(['provider' => 'paypal', 'status' => 'pending', 'amount' => $order->total_amount,
                'currency' => 'EUR', 'external_id' => $externalId]);
            $url = 'https://www.sandbox.paypal.com/checkoutnow?token='.$externalId;
            $payment->forceFill(['redirect_url' => $url])->save();
            $result = $this->service()->applyResult($payment->id, new PaymentResult($status, $externalId));
            $this->assertSame($status, $result->status);
            $this->assertNull($result->redirect_url);
            $repeated = $this->service()->applyResult($payment->id, new PaymentResult('pending', $externalId, $url));
            $this->assertSame($status, $repeated->status);
            $this->assertNull($repeated->redirect_url);
        }
    }

    private function confirmationFixture(): array
    {
        $this->configurePayPalHttp();
        [$user, $offer, $order] = $this->order();
        $payment = $this->service()->create($user, $order->id, 'paypal', 'confirmation-fixture');

        return [$user, $offer, $order, $payment];
    }

    private function confirmationDetails(string $status, ?string $captureStatus = null): array
    {
        $body = ['id' => '5O190127TN364715T', 'status' => $status, 'intent' => 'CAPTURE',
            'purchase_units' => [['amount' => ['value' => '24.68', 'currency_code' => 'EUR']]]];
        if ($captureStatus !== null) {
            $body['purchase_units'][0]['payments']['captures'] = [['id' => 'CAPTURE123', 'status' => $captureStatus,
                'amount' => ['value' => '24.68', 'currency_code' => 'EUR']]];
        }

        return $body;
    }

    private function fakeConfirmation(Payment $payment, ?callable $lookup = null, ?callable $capture = null): void
    {
        $assertPrepared = function () use ($payment): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNotNull($payment->fresh()->confirmation_started_at);
        };
        Http::fake([
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.$payment->external_id => function () use ($assertPrepared, $lookup) {
                $assertPrepared();

                return $lookup ? $lookup() : Http::response($this->confirmationDetails('APPROVED'));
            },
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.$payment->external_id.'/capture' => function (ClientRequest $request) use ($payment, $assertPrepared, $capture) {
                $assertPrepared();
                $this->assertSame(['paypal-capture-'.$payment->id], $request->header('PayPal-Request-Id'));

                return $capture ? $capture() : Http::response($this->confirmationDetails('COMPLETED', 'COMPLETED'), 201);
            },
        ]);
    }

    private function configurePayPalHttp(mixed $response = null): void
    {
        // Use the actual configured registry/DI and real gateway/client. Only HTTP is replaced.
        config(['payment.gateways' => (require config_path('payment.php'))['gateways'],
            'payment.paypal.enabled' => true, 'payment.paypal.client_id' => 'paypal-integration-client',
            'payment.paypal.client_secret' => 'paypal-integration-secret', 'payment.paypal.merchant_id' => 'sandbox-merchant',
            'payment.paypal.return_url' => 'http://localhost:5173/paypal-return',
            'payment.paypal.cancel_url' => 'http://localhost:5173/paypal-cancel', 'cache.default' => 'array']);
        Cache::store('array')->flush();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $response ??= Http::response($this->payPalOrderResponse(), 201);
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response([
                'access_token' => 'paypal-integration-token', 'token_type' => 'Bearer', 'expires_in' => 300,
            ]),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => function (ClientRequest $request, array $options) use ($response) {
                $this->assertSame(0, DB::transactionLevel(), 'Provider HTTP must execute outside database transactions.');
                $this->assertFalse($options['allow_redirects']);

                return is_callable($response) ? $response($request, $options) : $response;
            },
        ]);
    }

    private function payPalOrderResponse(): array
    {
        return ['id' => '5O190127TN364715T', 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [[
            'rel' => 'payer-action', 'method' => 'GET',
            'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=5O190127TN364715T',
        ]]];
    }

    private function assertPayPalCreationError(User $user, Order $order, string $key): void
    {
        try {
            $this->service()->create($user, $order->id, 'paypal', $key);
            $this->fail('Expected a PayPal creation failure.');
        } catch (HttpException $exception) {
            $this->assertSame(502, $exception->getStatusCode());
            $this->assertSame('The payment provider could not initialize this attempt.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
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
