<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\GameSearchController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    DB::select('SELECT 1');

    return response()->json(['status' => 'ok', 'database' => 'connected']);
});

Route::get('/games', [GameController::class, 'index']);

Route::get('/games/search', GameSearchController::class);

Route::get('/games/{game}', [GameController::class, 'show'])->whereNumber('game');
