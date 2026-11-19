<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['game_id', 'purchase_count'])]
class GamePopularity extends Model
{
    protected $primaryKey = 'game_id';

    public $incrementing = false;

    protected $attributes = ['purchase_count' => 0];

    protected function casts(): array
    {
        return ['purchase_count' => 'integer'];
    }

    public static function recordSale(int $gameId, int $units): void
    {
        $now = now();
        DB::statement(<<<'SQL'
            INSERT INTO game_popularities (game_id, purchase_count, created_at, updated_at)
            VALUES (?, ?, ?, ?)
            ON CONFLICT (game_id) DO UPDATE
            SET purchase_count = game_popularities.purchase_count + excluded.purchase_count,
                updated_at = excluded.updated_at
            SQL, [$gameId, $units, $now, $now]);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
