<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogFilters;
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
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.$maxPage],
        ]);
        $filters = array_intersect_key($validated, $filterRules);

        $query = Game::query()->select(['games.id', 'games.name', 'games.location', 'games.img'])
            ->where('games.is_active', true);
        CatalogFilters::apply($query, $filters);
        $alphabetical = $request->query('sort') === 'alphabetical';

        if ($alphabetical) {
            $query->orderByRaw('LOWER(games.name) ASC')->orderBy('games.id');
        } else {
            $query->join('game_popularities', 'game_popularities.game_id', '=', 'games.id')
                ->orderByDesc('game_popularities.purchase_count')
                ->orderBy('game_popularities.game_id');
        }

        $games = $query->paginate($perPage, ['games.id', 'games.name', 'games.location', 'games.img'], 'page', (int) ($validated['page'] ?? 1));
        $games->appends($filters);
        if ($alphabetical) {
            $games->appends(['sort' => 'alphabetical']);
        }

        return GameResource::collection($games);
    }

    public function show(Game $game): GameResource
    {
        abort_unless($game->is_active, 404);

        $game->load(['availableOffers' => fn ($offers) => $offers
            ->select(['id', 'game_id', 'seller_id', 'price', 'quantity'])
            ->orderBy('price')->orderBy('id')->with('seller:id,name')]);
        $game->setAttribute('min_price', $game->availableOffers->first()?->price);
        $game->setAttribute('offers_count', $game->availableOffers->count());

        return new GameResource($game);
    }
}
