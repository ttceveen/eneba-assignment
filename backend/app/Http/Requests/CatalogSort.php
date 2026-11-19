<?php

namespace App\Http\Requests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class CatalogSort
{
    public static function rules(): array
    {
        // Keep the existing All Games URL and the explicit popular API value.
        return ['sometimes', 'required', 'string', Rule::in([
            'price_asc', 'price_desc', 'name_asc', 'name_desc', 'newest', 'oldest', 'alphabetical', 'popular',
        ])];
    }

    public static function apply(Builder $games, string $sort): void
    {
        $games->reorder();

        if ($sort === 'popular') {
            $games->leftJoin('game_popularities', 'game_popularities.game_id', '=', 'games.id')
                ->orderByRaw('COALESCE(game_popularities.purchase_count, 0) DESC')
                ->orderBy('games.id');

            return;
        }

        $order = match ($sort) {
            'price_asc' => 'min_price ASC NULLS LAST',
            'price_desc' => 'min_price DESC NULLS LAST',
            'name_asc', 'alphabetical' => 'LOWER(games.name) ASC',
            'name_desc' => 'LOWER(games.name) DESC',
            'newest' => 'games.created_at DESC NULLS LAST',
            'oldest' => 'games.created_at ASC NULLS LAST',
        };

        $games->orderByRaw($order)->orderBy('games.id', $sort === 'newest' ? 'desc' : 'asc');
    }
}
