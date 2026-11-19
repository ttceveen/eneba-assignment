<?php

namespace Tests\Postgres;

use App\Models\Game;
use App\Models\Offer;
use App\Models\Seller;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// Separate suite, no RefreshDatabase and no relaxation of Tests\TestCase's SQLite guard.
class GameSearchTest extends TestCase
{
    private static Application $app;

    public static function setUpBeforeClass(): void
    {
        foreach (['SEARCH_TEST_SANDBOX' => '1', 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'search-postgres', 'DB_DATABASE' => 'eneba_search_test', 'DB_USERNAME' => 'eneba_search_test',
            'DATABASE_URL' => '', 'DB_URL' => ''] as $key => $value) {
            if (getenv($key) !== $value) {
                throw new RuntimeException('Refusing PostgreSQL tests outside the disposable search Compose project.');
            }
        }
        self::$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        self::$app->make(ConsoleKernel::class)->bootstrap();
        $config = config('database.connections.'.config('database.default'));
        if (! self::$app->environment('testing') || $config['driver'] !== 'pgsql'
            || $config['host'] !== 'search-postgres' || $config['database'] !== 'eneba_search_test'
            || $config['username'] !== 'eneba_search_test' || ! empty($config['url'])) {
            throw new RuntimeException('Unsafe resolved PostgreSQL test configuration.');
        }
        $identity = DB::selectOne('SELECT current_database() AS db, current_user AS username');
        if ($identity->db !== 'eneba_search_test' || $identity->username !== 'eneba_search_test'
            || DB::selectOne("SELECT count(*) AS n FROM information_schema.tables WHERE table_schema = 'public'")->n !== 0) {
            throw new RuntimeException('Tests require a new, empty disposable database.');
        }
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Disposable database migration failed.');
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

    private function game(string $name, bool $active = true): Game
    {
        return Game::create(['name' => $name, 'location' => ['GLOBAL'], 'img' => null, 'is_active' => $active]);
    }

    private function search(string $query, int $page = 1, bool $preview = false, array $filters = []): array
    {
        $request = Request::create('/api/games/search?'.http_build_query(['q' => $query, 'page' => $page, 'preview' => (int) $preview, ...$filters]));
        $request->headers->set('Accept', 'application/json');
        $kernel = self::$app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $kernel->terminate($request, $response);

        return $payload;
    }

    public function test_search_keeps_platform_region_and_price_filters(): void
    {
        $matching = $this->game('Portal');
        $wrongPlatform = $this->game('Portal 2');
        $unrelated = $this->game('Unrelated racing title');
        $seller = Seller::create(['name' => 'Seller']);

        foreach ([[$matching, 'Steam'], [$wrongPlatform, 'Xbox'], [$unrelated, 'Steam']] as [$game, $platform]) {
            $game->offers()->create(['seller_id' => $seller->id, 'platform' => $platform, 'price' => 20, 'quantity' => 1]);
        }

        $filters = ['platform' => 'Steam', 'region' => 'Global', 'min_price' => '20', 'max_price' => '20'];
        $result = $this->search('portal', filters: $filters);
        $this->assertSame([$matching->id], array_column($result['data'], 'id'));
        parse_str(parse_url($result['links']['first'], PHP_URL_QUERY), $params);
        $this->assertSame($filters, array_intersect_key($params, $filters));
        $this->assertSame([], $this->search('portal', filters: ['platform' => 'Nintendo'])['data']);
    }

    public function test_explicit_price_sort_reorders_fuzzy_search_using_all_matching_filters(): void
    {
        $exact = $this->game('Portal');
        $extension = $this->game('Portal Adventures');
        $wrongRegion = Game::create(['name' => 'Portal Europe', 'location' => ['PL']]);
        $wrongPlatform = $this->game('Portal Xbox');
        $wrongPrice = $this->game('Portal Expensive');
        $seller = Seller::create(['name' => 'Seller']);
        $other = Seller::create(['name' => 'Other seller']);
        foreach ([[$exact, 'Steam', 30], [$extension, 'Steam', 20], [$wrongRegion, 'Steam', 15],
            [$wrongPlatform, 'Xbox', 10], [$wrongPrice, 'Steam', 60]] as [$game, $platform, $price]) {
            $game->offers()->create(['seller_id' => $seller->id, 'platform' => $platform, 'price' => $price, 'quantity' => 1]);
        }
        $exact->offers()->create(['seller_id' => $other->id, 'platform' => 'Steam', 'price' => 5, 'quantity' => 1]);

        $filters = ['platform' => 'Steam', 'region' => 'Global', 'min_price' => '10', 'max_price' => '50'];
        $this->assertSame([$exact->id, $extension->id], array_column($this->search('portal', filters: $filters)['data'], 'id'));
        $extension->popularity()->update(['purchase_count' => 2]);
        $this->assertSame([$extension->id, $exact->id],
            array_column($this->search('portal', filters: [...$filters, 'sort' => 'popular'])['data'], 'id'));
        $filters['sort'] = 'price_asc';
        $result = $this->search('portl', filters: $filters);
        $this->assertSame([$extension->id, $exact->id], array_column($result['data'], 'id'));
        $this->assertSame(['20.00', '30.00'], array_column($result['data'], 'min_price'));
        parse_str(parse_url($result['links']['first'], PHP_URL_QUERY), $params);
        $this->assertSame(['q' => 'portl', ...$filters], array_diff_key($params, ['page' => true]));
    }

    public function test_platform_migration_preserves_offers_and_enforces_enum_and_not_null(): void
    {
        $migration = require database_path('migrations/2026_11_18_000001_add_platform_to_offers.php');
        $migration->down();
        $seller = Seller::create(['name' => 'Legacy seller']);
        for ($i = 0; $i < 8; $i++) {
            $game = $this->game('Legacy game '.$i);
            $game->offers()->create(['seller_id' => $seller->id, 'price' => $i + 10, 'quantity' => 1]);
        }
        $snapshot = fn () => DB::table('offers')->orderBy('id')->get()
            ->map(fn ($offer) => array_diff_key((array) $offer, ['platform' => true]))->all();
        $before = $snapshot();

        $migration->up();

        $this->assertSame($before, $snapshot());
        $this->assertSame(0, DB::table('offers')->whereNull('platform')->count());
        $this->assertEqualsCanonicalizing(Offer::PLATFORMS, DB::table('offers')->distinct()->pluck('platform')->all());
        foreach (['22P02' => 'Unsupported', '23502' => null] as $code => $value) {
            try {
                DB::transaction(fn () => DB::table('offers')->where('id', $before[0]['id'])->update(['platform' => $value]));
                $this->fail('PostgreSQL accepted an invalid platform.');
            } catch (QueryException $exception) {
                $this->assertSame((string) $code, $exception->getCode());
            }
        }
    }

    public function test_exact_prefix_substring_and_typo_rank_in_order(): void
    {
        $substring = $this->game('The Witcher 3: Wild Hunt');
        $prefix = $this->game('Witcher Adventures');
        $exact = $this->game('Witcher');
        $fuzzy = $this->game('Wicher');
        $this->game('Witcher', false);
        $this->game('Unrelated racing title');
        $this->assertSame([$exact->id, $prefix->id, $substring->id, $fuzzy->id], array_column($this->search('WITCHER')['data'], 'id'));
    }

    public function test_typo_matches_a_word_inside_a_long_title(): void
    {
        $game = $this->game('The Witcher 3: Wild Hunt');
        $this->assertContains($game->id, array_column($this->search('wicher')['data'], 'id'));
    }

    public function test_exact_ties_have_stable_id_order(): void
    {
        $first = $this->game('Portal');
        $second = $this->game('portal');
        $this->assertSame([$first->id, $second->id], array_column($this->search('PORTAL')['data'], 'id'));
    }

    public function test_pages_are_disjoint_and_keep_query_and_resource_contract(): void
    {
        $ids = [];
        for ($i = 0; $i < 28; $i++) {
            $ids[] = $this->game('Witcher Saga')->id;
        }
        $this->game('Witcher Saga', false);
        $seen = [];
        foreach ([1 => 12, 2 => 12, 3 => 4, 4 => 0] as $page => $count) {
            $payload = $this->search('witcher', $page);
            $this->assertCount($count, $payload['data']);
            $this->assertSame(28, $payload['meta']['total']);
            $this->assertSame(12, $payload['meta']['per_page']);
            $this->assertSame(3, $payload['meta']['last_page']);
            $this->assertSame($page, $payload['meta']['current_page']);
            foreach ($payload['links'] as $url) {
                if ($url !== null) {
                    parse_str(parse_url($url, PHP_URL_QUERY), $query);
                    $this->assertSame('witcher', $query['q']);
                }
            }
            foreach ($payload['data'] as $game) {
                $this->assertSame(['id', 'name', 'location', 'img', 'min_price', 'offers_count'], array_keys($game));
                $this->assertIsArray($game['location']);
                $this->assertNull($game['img']);
            }
            $seen = array_merge($seen, array_column($payload['data'], 'id'));
        }
        $this->assertSame($ids, $seen);
        $this->assertSame([], $this->search('witcher', intdiv(PHP_INT_MAX, 12) + 1)['data']);
    }

    public function test_preview_is_limited_deduplicated_and_uses_same_relevance(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->game($i < 3 ? 'Witcher' : 'Witcher Adventure '.$i);
        }
        $this->game('Witcher Hidden', false);
        $full = $this->search('witch');
        $preview = $this->search('witch', preview: true);
        $this->assertCount(6, $preview['data']);
        $this->assertSame(array_slice($full['data'], 0, 6), $preview['data']);
        $this->assertSame(array_values(array_unique(array_column($preview['data'], 'name'))), $preview['suggestions']);
        $this->assertNotContains('Witcher Hidden', $preview['suggestions']);
        $this->assertArrayNotHasKey('meta', $preview);
    }

    public function test_percent_underscore_escape_and_quotes_are_literals(): void
    {
        foreach (['%', '_', '!', "' OR 1=1 --"] as $literal) {
            $game = $this->game($literal);
            $this->game('Unrelated game');
            $this->assertSame([$game->id], array_column($this->search($literal)['data'], 'id'));
        }
    }

    public function test_no_matches_return_empty_page_and_preview(): void
    {
        $this->game('Portal');
        $this->assertSame([], $this->search('zzzzzzzz')['data']);
        $this->assertSame(0, $this->search('zzzzzzzz')['meta']['total']);
        $this->assertSame(['data' => [], 'suggestions' => []], $this->search('zzzzzzzz', preview: true));
    }

    public function test_unicode_case_and_trimmed_query(): void
    {
        $game = $this->game('Ведьмак');
        $this->assertSame([$game->id], array_column($this->search('  ВЕДЬМАК  ')['data'], 'id'));
    }
}
