<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\Offer;
use App\Models\Seller;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OfferSeeder extends Seeder
{
    public function run(): void
    {
        $sellers = Seller::whereIn('name', SellerSeeder::NAMES)->orderBy('id')->get()->unique('name')->values();
        if ($sellers->count() !== 5) {
            throw new RuntimeException('Run SellerSeeder before OfferSeeder.');
        }

        DB::transaction(function () use ($sellers): void {
            Game::orderBy('id')->chunkById(100, function ($games) use ($sellers): void {
                foreach ($games as $game) {
                    // Demonstration prices only; no real commercial pricing data.
                    $base = [999, 1499, 1999, 2499, 2999, 3999, 4999, 5999][($game->id - 1) % 8];
                    for ($index = 0; $index < 2 + $game->id % 3; $index++) {
                        $cents = $base + $index * 125;
                        Offer::firstOrCreate([
                            'seller_id' => $sellers[($game->id + $index) % 5]->id,
                            'game_id' => $game->id,
                        ], [
                            'price' => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100),
                            'quantity' => 3 + $index,
                            'is_active' => true,
                        ]);
                    }
                }
            });
        });
    }
}
