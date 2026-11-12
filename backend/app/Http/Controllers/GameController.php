<?php

namespace App\Http\Controllers;

use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GameController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return GameResource::collection(
            Game::query()
                ->join('game_popularities', 'game_popularities.game_id', '=', 'games.id')
                ->where('games.is_active', true)
                ->orderByDesc('game_popularities.purchase_count')
                ->orderBy('game_popularities.game_id')
                ->paginate(12, ['games.id', 'games.name', 'games.location', 'games.img'])
        );
    }

    public function show(Game $game): GameResource
    {
        abort_unless($game->is_active, 404);

        return new GameResource($game);
    }
}
