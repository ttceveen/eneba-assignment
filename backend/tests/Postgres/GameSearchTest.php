<?php

namespace Tests\Postgres;

use App\Models\Game;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
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

    private function search(string $query, int $page = 1, bool $preview = false): array
    {
        $request = Request::create('/api/games/search?'.http_build_query(['q' => $query, 'page' => $page, 'preview' => (int) $preview]));
        $request->headers->set('Accept', 'application/json');
        $kernel = self::$app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $kernel->terminate($request, $response);

        return $payload;
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
                $this->assertSame(['id', 'name', 'location', 'img'], array_keys($game));
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
