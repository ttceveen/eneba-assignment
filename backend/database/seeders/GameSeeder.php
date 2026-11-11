<?php

namespace Database\Seeders;

use App\Models\Game;
use Illuminate\Database\Seeder;

class GameSeeder extends Seeder
{
    public function run(): void
    {
        // Sample availability data, not actual sales restrictions.
        $games = [
            ['name' => 'Cyberpunk 2077', 'location' => ['GLOBAL']],
            ['name' => 'The Witcher 3: Wild Hunt', 'location' => ['GLOBAL']],
            ['name' => 'Grand Theft Auto V', 'location' => ['LT', 'PL', 'DE']],
            ['name' => 'Red Dead Redemption 2', 'location' => ['GLOBAL']],
            ['name' => 'Hogwarts Legacy', 'location' => ['GLOBAL']],
            ['name' => 'Elden Ring', 'location' => ['GLOBAL']],
            ['name' => "Baldur's Gate 3", 'location' => ['GLOBAL']],
            ['name' => 'Minecraft', 'location' => ['GLOBAL']],
            ['name' => 'Resident Evil 4', 'location' => ['LT', 'PL', 'DE']],
            ['name' => 'God of War', 'location' => ['GLOBAL']],
        ];

        foreach ($games as $game) {
            Game::firstOrCreate(
                ['name' => $game['name']],
                ['location' => $game['location'], 'img' => null],
            );
        }
    }
}
