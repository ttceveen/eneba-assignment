<?php

namespace Database\Seeders;

use App\Models\Seller;
use Illuminate\Database\Seeder;

class SellerSeeder extends Seeder
{
    public const NAMES = ['GameVault', 'DigitalWorld', 'KeyMarket', 'PixelStore', 'GameHub'];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            Seller::firstOrCreate(['name' => $name], ['is_active' => true]);
        }
    }
}
