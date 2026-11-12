<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['offer_id', 'key_value', 'status'])]
class DigitalKey extends Model
{
    protected $attributes = ['status' => 'available'];

    protected $hidden = ['key_value'];

    protected function casts(): array
    {
        return ['key_value' => 'encrypted'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
