<?php

use App\Exceptions\CheckoutConflictException;
use App\Models\Payment;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// Independent connection used only by the guarded disposable concurrency suite.
require dirname(__DIR__, 2).'/vendor/autoload.php';
if (getenv('SEARCH_TEST_SANDBOX') !== '1' || getenv('DB_HOST') !== 'search-postgres'
    || getenv('DB_DATABASE') !== 'eneba_search_test' || getenv('APP_ENV') !== 'testing'
    || getenv('DATABASE_URL') !== '' || getenv('DB_URL') !== '') {
    exit(2);
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.pgsql.host') !== 'search-postgres' || config('database.connections.pgsql.database') !== 'eneba_search_test') {
    exit(2);
}
DB::select("SELECT set_config('application_name', ?, false)", [$argv[4]]);
echo "READY\n";
flush();
fgets(STDIN);
try {
    $checkout = app(CheckoutService::class);
    $payments = app(PaymentService::class);
    $user = User::findOrFail((int) $argv[2]);
    $data = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
    if ($argv[1] === 'confirm') {
        config(['payment.paypal.enabled' => true, 'payment.paypal.client_id' => 'worker-fixture',
            'payment.paypal.client_secret' => 'worker-fixture-secret', 'payment.paypal.return_url' => 'http://localhost/return',
            'payment.paypal.cancel_url' => 'http://localhost/cancel']);
        $fixture = Payment::findOrFail($data['id']);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($fixture) {
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('HTTP inside a transaction.');
            }
            if ($request->url() === 'https://api-m.sandbox.paypal.com/v1/oauth2/token') {
                return Http::response(['access_token' => 'worker-token', 'token_type' => 'Bearer', 'expires_in' => 300]);
            }
            $base = 'https://api-m.sandbox.paypal.com/v2/checkout/orders/'.$fixture->external_id;
            if ($request->url() !== $base && $request->url() !== $base.'/capture') {
                throw new RuntimeException('Unexpected provider request.');
            }
            $isCapture = $request->url() === $base.'/capture';
            if ($isCapture && $request->header('PayPal-Request-Id') !== ['paypal-capture-'.$fixture->id]) {
                throw new RuntimeException('Unexpected capture key.');
            }
            $body = ['id' => $fixture->external_id, 'intent' => 'CAPTURE', 'status' => $isCapture ? 'COMPLETED' : 'APPROVED',
                'purchase_units' => [['amount' => ['value' => $fixture->amount, 'currency_code' => 'EUR']]]];
            if ($isCapture) {
                $body['purchase_units'][0]['payments']['captures'] = [['id' => 'CAPTURE123', 'status' => 'COMPLETED',
                    'amount' => ['value' => $fixture->amount, 'currency_code' => 'EUR']]];
            }

            return Http::response($body);
        });
    }
    $entity = match ($argv[1]) {
        'confirm' => $payments->confirm($user, $data['paypal_order_id']),
        'simulate' => $payments->simulate($user, $data['id'], 'success'),
        'pay' => $payments->create($user, $data['id'], 'fake_card', $data['key']),
        'cancel' => $checkout->cancel($user, $data['id']),
        'get' => $checkout->get($user, $data['id']),
    };
    echo json_encode(['status' => $argv[1] === 'pay' ? 201 : 200, 'id' => $entity->id]);
} catch (CheckoutConflictException) {
    echo json_encode(['status' => 409]);
} catch (Throwable) {
    // Never include SQL, bindings, token or key material in child output.
    echo json_encode(['status' => 500]);
}
