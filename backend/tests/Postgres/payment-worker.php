<?php

use App\Exceptions\CheckoutConflictException;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
    $entity = match ($argv[1]) {
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
