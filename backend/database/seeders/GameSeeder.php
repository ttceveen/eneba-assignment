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
            ['name' => "Starfield", 'location' => ['GLOBAL']],
            ['name' => "Fallout 4", 'location' => ['GLOBAL']],
            ['name' => "Fallout: New Vegas", 'location' => ['GLOBAL']],
            ['name' => "The Elder Scrolls V: Skyrim", 'location' => ['GLOBAL']],
            ['name' => "Assassin's Creed Valhalla", 'location' => ['GLOBAL']],
            ['name' => "Assassin's Creed Odyssey", 'location' => ['GLOBAL']],
            ['name' => "Assassin's Creed Origins", 'location' => ['GLOBAL']],
            ['name' => "Far Cry 6", 'location' => ['GLOBAL']],
            ['name' => "Far Cry 5", 'location' => ['GLOBAL']],
            ['name' => "Watch Dogs: Legion", 'location' => ['GLOBAL']],
            ['name' => "Dying Light 2 Stay Human", 'location' => ['GLOBAL']],
            ['name' => "Dead Space", 'location' => ['GLOBAL']],
            ['name' => "DOOM Eternal", 'location' => ['GLOBAL']],
            ['name' => "Forza Horizon 5", 'location' => ['GLOBAL']],
            ['name' => "Forza Horizon 4", 'location' => ['GLOBAL']],
            ['name' => "Horizon Zero Dawn", 'location' => ['GLOBAL']],
            ['name' => "Horizon Forbidden West", 'location' => ['GLOBAL']],
            ['name' => "Marvel's Spider-Man Remastered", 'location' => ['GLOBAL']],
            ['name' => "Marvel's Spider-Man: Miles Morales", 'location' => ['GLOBAL']],
            ['name' => "The Last of Us Part I", 'location' => ['GLOBAL']],
            ['name' => "Death Stranding", 'location' => ['GLOBAL']],
            ['name' => "Control Ultimate Edition", 'location' => ['GLOBAL']],
            ['name' => "Alan Wake 2", 'location' => ['GLOBAL']],
            ['name' => "Metro Exodus", 'location' => ['GLOBAL']],
            ['name' => "Kingdom Come: Deliverance", 'location' => ['GLOBAL']],
            ['name' => "Kingdom Come: Deliverance II", 'location' => ['GLOBAL']],
            ['name' => "Diablo IV", 'location' => ['GLOBAL']],
            ['name' => "Monster Hunter: World", 'location' => ['GLOBAL']],
            ['name' => "Monster Hunter Wilds", 'location' => ['GLOBAL']],
            ['name' => "Sekiro: Shadows Die Twice", 'location' => ['GLOBAL']],
        ];

        foreach ($games as $game) {
            Game::firstOrCreate(
                ['name' => $game['name']],
                ['location' => $game['location'], 'img' => null],
            );
        }
    }
}
