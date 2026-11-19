<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogFilters;
use App\Http\Requests\CatalogSort;
use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GameController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = 12;
        // (page - 1) * perPage must remain an integer within PHP_INT_MAX.
        $maxPage = intdiv(PHP_INT_MAX, $perPage) + 1;
        $filterRules = CatalogFilters::rules($request->has('min_price'));
        $validated = $request->validate([
            ...$filterRules,
            'sort' => CatalogSort::rules(),
            'ids' => ['sometimes', 'required', 'array', 'list', 'max:12'],
            'ids.*' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX, 'distinct'],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.$maxPage],
        ]);
        $filters = array_intersect_key($validated, $filterRules);
        $sort = $validated['sort'] ?? null;

        $query = Game::query()->select(['games.id', 'games.name', 'games.location', 'games.img'])
            ->where('games.is_active', true);
        if (isset($validated['ids'])) {
            $query->whereIn('games.id', $validated['ids']);
        }

        CatalogFilters::apply($query, $filters);
        CatalogSort::apply($query, $sort ?? 'popular');

        $games = $query->paginate($perPage, ['games.id', 'games.name', 'games.location', 'games.img'], 'page', (int) ($validated['page'] ?? 1));
        $games->appends($filters + array_intersect_key($validated, ['ids' => true]));
        if ($sort !== null) {
            $games->appends(['sort' => $sort]);
        }

        return GameResource::collection($games);
    }

    public function show(Game $game): GameResource
    {
        abort_unless($game->is_active, 404);

        $game->load(['availableOffers' => fn ($offers) => $offers
            ->select(['id', 'game_id', 'seller_id', 'price', 'quantity', 'platform'])
            ->orderBy('price')->orderBy('id')->with('seller:id,name')]);
        $game->setAttribute('min_price', $game->availableOffers->first()?->price);
        $game->setAttribute('offers_count', $game->availableOffers->count());

        return new GameResource($game);
    }
}
