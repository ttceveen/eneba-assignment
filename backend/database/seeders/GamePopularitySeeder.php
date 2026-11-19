<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\GamePopularity;
use App\Models\Order;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GamePopularitySeeder extends Seeder
{
    private const INITIALIZED = 'demo-game-popularity-initialized';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Demo popularity may only be initialized in local or testing environments.');
        }

        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                // Serialize initialization with other seed runs and sale counter writes.
                DB::statement('LOCK TABLE game_popularities IN SHARE ROW EXCLUSIVE MODE');
            }

            $markers = Cache::build([
                'driver' => 'database',
                'connection' => DB::getDefaultConnection(),
                'table' => 'cache',
            ]);
            if ($markers->has(self::INITIALIZED)) {
                $this->command?->warn('Demo popularity skipped: already initialized.');

                return;
            }

            if (GamePopularity::where('purchase_count', '>', 0)->exists() || Order::where('status', 'paid')->exists()) {
                $this->command?->warn('Demo popularity skipped: nonzero counters or paid orders already exist.');

                return;
            }

            $ids = Game::query()->pluck('id')
                ->sortBy(fn (int $id): string => hash('sha256', 'eneba-demo-game:'.$id))
                ->values();
            if ($ids->isEmpty()) {
                $this->command?->warn('Demo popularity skipped: there are no games.');

                return;
            }

            $count = (int) round($ids->count() * 0.30);

            foreach ($ids->take($count) as $id) {
                $value = 1 + hexdec(substr(hash('sha256', 'eneba-demo-count:'.$id), 0, 6)) % 1000;
                GamePopularity::updateOrCreate(['game_id' => $id], ['purchase_count' => $value]);
            }

            if (! $markers->forever(self::INITIALIZED, true)) {
                throw new RuntimeException('Could not save the demo popularity initialization marker.');
            }

            $this->command?->info("Demo popularity: {$count} of {$ids->count()} games received a positive counter.");
        });
    }
}
