<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'location', 'img', 'is_active'])]
class Game extends Model
{
    protected $attributes = ['is_active' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Game $game): void {
            $game->popularity()->create(['purchase_count' => 0]);
        });
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }

        // The insert and created event must succeed or roll back together.
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    public function popularity(): HasOne
    {
        return $this->hasOne(GamePopularity::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function availableOffers(): HasMany
    {
        return $this->offers()->where('offers.is_active', true)->where('quantity', '>', 0)
            ->whereHas('seller', fn (Builder $seller) => $seller->where('is_active', true));
    }

    public function scopeWithOfferSummary(Builder $query): Builder
    {
        return $query->withMin('availableOffers as min_price', 'price')
            ->withCount('availableOffers as offers_count')
            ->withCasts(['min_price' => 'decimal:2']);
    }
}
