#!/bin/bash
set -eu
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$PROJECT_DIR"
BACKEND_CONTAINER="$(docker compose ps -q backend)"
BACKEND_IMAGE="$(docker inspect --format '{{.Config.Image}}' "$BACKEND_CONTAINER")"
NET=eneba-ui-e2e-net-$$
DB=eneba-ui-e2e-postgres-$$
RUNNER=eneba-ui-e2e-backend-$$
cleanup(){ docker rm -f "$RUNNER" "$DB" >/dev/null 2>&1 || true; docker network rm "$NET" >/dev/null 2>&1 || true; }
trap cleanup EXIT
docker network create "$NET" >/dev/null
docker run --pull=never --rm -d --name "$DB" --network "$NET" --network-alias search-postgres --tmpfs /var/lib/postgresql/data -e POSTGRES_DB=eneba_search_test -e POSTGRES_USER=eneba_search_test -e POSTGRES_PASSWORD=disposable-browser-fixture postgres:17-bookworm >/dev/null
for i in {1..40}; do if docker exec "$DB" pg_isready -U eneba_search_test -d eneba_search_test >/dev/null 2>&1; then break; fi; sleep 0.3; done
docker run --pull=never --rm -d --name "$RUNNER" --network "$NET" --volumes-from "$BACKEND_CONTAINER":ro -p 127.0.0.1:8011:8000 --entrypoint sleep \
 -e APP_ENV=testing -e APP_DEBUG=false -e APP_BASE_PATH=/tmp/checkout-ui -e APP_CONFIG_CACHE=/tmp/no-ui-config.php \
 -e APP_KEY=base64:MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTIzNDU2Nzg5MDE= -e DIGITAL_KEY_FINGERPRINT_SECRET=checkout-test-only-secret-32-bytes-minimum \
 -e PAYMENT_DEMO_ENABLED=true -e DB_CONNECTION=pgsql -e DB_HOST=search-postgres -e DB_PORT=5432 -e DB_DATABASE=eneba_search_test -e DB_USERNAME=eneba_search_test -e DB_PASSWORD=disposable-browser-fixture \
 -e DATABASE_URL= -e DB_URL= -e CACHE_STORE=array -e SESSION_DRIVER=array -e LOG_CHANNEL=null "$BACKEND_IMAGE" infinity >/dev/null
docker exec -i "$RUNNER" sh -s <<'RUNNER'
set -eu
mkdir -p /tmp/checkout-ui
tar -C /app --exclude=./vendor --exclude=./.env --exclude='./.env.*' --exclude=./storage --exclude=./bootstrap/cache --exclude=./.git --exclude=./.phpunit.cache -cf - . | tar -C /tmp/checkout-ui -xf -
cd /tmp/checkout-ui
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
ln -s /app/vendor vendor
cat > sandbox-autoload.php <<'PHP'
<?php
$loader=require '/app/vendor/autoload.php';
foreach($loader->getClassMap() as $class=>$file){foreach(['/app/app/','/app/tests/','/app/database/'] as $prefix){if(str_starts_with($file,$prefix))$loader->addClassMap([$class=>'/tmp/checkout-ui'.substr($file,4)]);}}
$loader->setPsr4('App\\', '/tmp/checkout-ui/app');
$loader->setPsr4('Database\\Factories\\', '/tmp/checkout-ui/database/factories');
PHP
cat > fixture.php <<'PHP'
<?php
require 'sandbox-autoload.php';
$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$c=config('database.connections.pgsql');
if($c['host']!=='search-postgres'||$c['database']!=='eneba_search_test'||!empty($c['url'])||!$app->environment('testing'))exit(2);
if(Illuminate\Support\Facades\DB::selectOne('SELECT current_database() AS db')->db!=='eneba_search_test'||Illuminate\Support\Facades\DB::selectOne("SELECT count(*) AS n FROM information_schema.tables WHERE table_schema='public'")->n!==0)exit(2);
Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true]);
$game=App\Models\Game::create(['name'=>'Synthetic Browser Checkout Fixture','location'=>['GLOBAL']]);
$seller=App\Models\Seller::create(['name'=>'Synthetic Browser Seller']);
$offer=App\Models\Offer::create(['game_id'=>$game->id,'seller_id'=>$seller->id,'price'=>'7.25','quantity'=>6]);
foreach(range(1,6) as $slot)$offer->digitalKeys()->create(['key_value'=>'BROWSER-DEMO-NOT-VALID-'.$slot]);
echo "Disposable browser fixture prepared; no working database used.\n";
PHP
php fixture.php
RUNNER
docker exec -d "$RUNNER" sh -c 'cd /tmp/checkout-ui; exec php -d auto_prepend_file=/tmp/checkout-ui/sandbox-autoload.php -S 0.0.0.0:8000 -t public public/index.php'
for i in {1..40}; do if curl -fsS http://127.0.0.1:8011/api/health >/dev/null 2>&1; then break; fi; sleep 0.2; done
CHECKOUT_SANDBOX_API_URL=http://127.0.0.1:8011 node --test frontend/tests/purchase-real.browser.mjs
docker exec "$DB" psql -X -U eneba_search_test -d eneba_search_test -Atc "SELECT 'orders',status,count(*) FROM orders GROUP BY status; SELECT 'payments',status,count(*) FROM payments GROUP BY status; SELECT 'keys',status,count(*) FROM digital_keys GROUP BY status; SELECT 'stock',quantity FROM offers;"
