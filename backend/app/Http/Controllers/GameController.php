<?php

namespace App\Http\Controllers;

use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GameController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Game::query()->where('games.is_active', true);
        $alphabetical = $request->query('sort') === 'alphabetical';

        if ($alphabetical) {
            $query->orderByRaw('LOWER(games.name) ASC')->orderBy('games.id');
        } else {
            $query->join('game_popularities', 'game_popularities.game_id', '=', 'games.id')
                ->orderByDesc('game_popularities.purchase_count')
                ->orderBy('game_popularities.game_id');
        }

        $games = $query->paginate(12, ['games.id', 'games.name', 'games.location', 'games.img']);
        if ($alphabetical) {
            $games->appends(['sort' => 'alphabetical']);
        }

        return GameResource::collection($games);
    }

    public function show(Game $game): GameResource
    {
        abort_unless($game->is_active, 404);

        return new GameResource($game);
    }
}
