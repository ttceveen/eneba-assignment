<?php

use App\Exceptions\CheckoutConflictException;
use App\Models\User;
use App\Services\CheckoutService;
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
    $service = app(CheckoutService::class);
    $user = User::findOrFail((int) $argv[2]);
    $data = json_decode($argv[3], true, flags: JSON_THROW_ON_ERROR);
    $order = match ($argv[1]) {
        'create' => $service->create($user, $data['items'], $data['key']),
        'cancel' => $service->cancel($user, $data['id']),
        'get' => $service->get($user, $data['id']),
    };
    echo json_encode(['status' => $argv[1] === 'create' ? 201 : 200, 'id' => $order->id]);
} catch (CheckoutConflictException) {
    echo json_encode(['status' => 409]);
} catch (Throwable) {
    // Never include SQL, bindings, token or key material in child output.
    echo json_encode(['status' => 500]);
}
