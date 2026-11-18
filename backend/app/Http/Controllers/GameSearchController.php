<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogFilters;
use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class GameSearchController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $input = $request->query('q');
        if (is_string($input)) {
            $request->merge(['q' => preg_replace('/^\s+|\s+$/u', '', $input)]);
        }
        $filterRules = CatalogFilters::rules($request->has('min_price'));
        $validated = $request->validate([
            ...$filterRules,
            'q' => ['required', 'string', 'max:255'],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.(intdiv(PHP_INT_MAX, 12) + 1)],
            'preview' => ['sometimes', 'boolean'],
        ]);
        $filters = array_intersect_key($validated, $filterRules);

        abort_unless(DB::connection()->getDriverName() === 'pgsql', 503, 'Search requires PostgreSQL and pg_trgm.');
        abort_unless(DB::selectOne("SELECT to_regprocedure('word_similarity(text,text)') IS NOT NULL AS available")->available,
            503, 'Search requires the pg_trgm migration.');

        $query = CatalogFilters::apply($this->rankedGames($validated['q']), $filters);
        if ($request->boolean('preview')) {
            $games = $query->limit(6)->get(['games.id', 'games.name', 'games.location', 'games.img']);

            return GameResource::collection($games)->additional([
                'suggestions' => $games->pluck('name')->unique(fn (string $name): string => mb_strtolower($name))->values()->all(),
            ]);
        }

        $games = $query->paginate(12, ['games.id', 'games.name', 'games.location', 'games.img'], 'page', (int) ($validated['page'] ?? 1));
        $games->appends(['q' => $validated['q'], ...$filters]);

        return GameResource::collection($games);
    }

    private function rankedGames(string $query): Builder
    {
        // Escape LIKE metacharacters; all SQL values remain bound parameters.
        $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);
        $prefix = $literal.'%';
        $contains = '%'.$literal.'%';

        return Game::query()->select(['games.id', 'games.name', 'games.location', 'games.img'])
            ->where('games.is_active', true)
            ->whereRaw("(games.name ILIKE ? ESCAPE '!' OR word_similarity(LOWER(?), LOWER(games.name)) >= ?)", [$contains, $query, 0.45])
            ->orderByRaw("CASE WHEN LOWER(games.name) = LOWER(?) THEN 0 WHEN games.name ILIKE ? ESCAPE '!' THEN 1 WHEN games.name ILIKE ? ESCAPE '!' THEN 2 ELSE 3 END", [$query, $prefix, $contains])
            ->orderByRaw('word_similarity(LOWER(?), LOWER(games.name)) DESC', [$query])
            ->orderBy('games.id');
    }
}
