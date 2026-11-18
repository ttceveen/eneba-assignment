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
            'price_asc', 'price_desc', 'name_asc', 'name_desc', 'newest', 'alphabetical', 'popular',
        ])];
    }

    public static function apply(Builder $games, string $sort): void
    {
        $order = match ($sort) {
            'price_asc' => 'min_price ASC NULLS LAST',
            'price_desc' => 'min_price DESC NULLS LAST',
            'name_asc', 'alphabetical' => 'LOWER(games.name) ASC',
            'name_desc' => 'LOWER(games.name) DESC',
            'newest' => 'games.created_at DESC NULLS LAST',
        };

        $games->reorder()->orderByRaw($order)->orderBy('games.id');
    }
}
