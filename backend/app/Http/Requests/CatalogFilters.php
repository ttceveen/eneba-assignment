<?php

namespace App\Http\Requests;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class CatalogFilters
{
    private const REGIONS = [
        'Global' => ['GLOBAL'],
        'Europe' => ['LT', 'PL', 'DE'],
        'North America' => [],
    ];

    public static function rules(bool $hasMinimum): array
    {
        return [
            'popular_only' => ['sometimes', 'required', 'boolean'],
            'platform' => ['sometimes', 'required', 'string', Rule::in(Offer::PLATFORMS)],
            'region' => ['sometimes', 'required', 'string', Rule::in(array_keys(self::REGIONS))],
            'min_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'required', 'numeric', 'min:0', ...($hasMinimum ? ['gte:min_price'] : [])],
        ];
    }

    public static function apply(Builder $games, array $filters): Builder
    {
        if (! empty($filters['popular_only'])) {
            $games->whereHas('popularity', fn (Builder $popularity) => $popularity->where('purchase_count', '>', 0));
        }

        if (isset($filters['region'])) {
            $locations = self::REGIONS[$filters['region']];
            $games->where(function (Builder $query) use ($locations): void {
                $query->whereRaw('1 = 0');
                foreach ($locations as $location) {
                    $query->orWhereJsonContains('games.location', $location);
                }
            });
        }

        $matchingOffers = function (Builder $offers) use ($filters): void {
            foreach (['platform' => ['platform', '='], 'min_price' => ['price', '>='], 'max_price' => ['price', '<=']] as $filter => [$column, $operator]) {
                if (isset($filters[$filter])) {
                    $offers->where($column, $operator, $filters[$filter]);
                }
            }
        };

        if (isset($filters['platform']) || isset($filters['region']) || isset($filters['min_price']) || isset($filters['max_price'])) {
            $games->whereHas('availableOffers', $matchingOffers);
        }

        return $games->withOfferSummary($matchingOffers);
    }
}
