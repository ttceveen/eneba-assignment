<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\GamePopularity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GamePopularitySeeder extends Seeder
{
    public function run(): void
    {
        // Demonstration values, not actual purchases. Only zero counters are eligible.
        $counts = [
            'Cyberpunk 2077' => 150,
            'The Witcher 3: Wild Hunt' => 320,
            'Elden Ring' => 75,
            "Baldur's Gate 3" => 200,
            'Grand Theft Auto V' => 110,
        ];

        DB::transaction(function () use ($counts): void {
            foreach ($counts as $name => $count) {
                $game = Game::query()->where('name', $name)->orderBy('id')->first();
                if ($game) {
                    GamePopularity::query()->where('game_id', $game->id)
                        ->where('purchase_count', 0)->update(['purchase_count' => $count]);
                }
            }
        });
    }
}
