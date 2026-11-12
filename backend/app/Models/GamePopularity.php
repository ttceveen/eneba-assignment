<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['game_id', 'purchase_count'])]
class GamePopularity extends Model
{
    protected $primaryKey = 'game_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $attributes = ['purchase_count' => 0];

    protected function casts(): array
    {
        return ['purchase_count' => 'integer'];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
