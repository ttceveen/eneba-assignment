<?php

namespace App\Http\Controllers;

use App\Http\Resources\GameResource;
use App\Models\Game;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GameController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return GameResource::collection(Game::query()->orderBy('id')->paginate(12, ['id', 'name', 'location', 'img']));
    }

    public function show(Game $game): GameResource
    {
        return new GameResource($game);
    }
}
