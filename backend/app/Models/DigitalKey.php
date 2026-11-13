<?php

namespace App\Models;

use App\Exceptions\DuplicateDigitalKeyException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;

#[Fillable(['offer_id', 'key_value', 'status', 'order_item_id', 'reserved_until'])]
class DigitalKey extends Model
{
    protected $attributes = ['status' => 'available'];

    protected $hidden = ['key_value', 'key_fingerprint'];

    protected function casts(): array
    {
        return ['key_value' => 'encrypted', 'reserved_until' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (DigitalKey $key): void {
            if ($key->isDirty('key_value')) {
                $key->key_fingerprint = self::fingerprint($key->key_value);
            }
        });
    }

    public static function fingerprint(string $value): string
    {
        $secret = config('checkout.key_fingerprint_secret');
        if (! is_string($secret) || strlen($secret) < 32) {
            throw new \RuntimeException('Configure a separate DIGITAL_KEY_FINGERPRINT_SECRET of at least 32 bytes before writing keys.');
        }

        $appKey = (string) config('app.key');
        $decodedKey = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if (hash_equals($appKey, $secret) || (is_string($decodedKey) && hash_equals($decodedKey, $secret))) {
            throw new \RuntimeException('DIGITAL_KEY_FINGERPRINT_SECRET must be independent of APP_KEY.');
        }

        return hash_hmac('sha256', $value, $secret);
    }

    public function save(array $options = []): bool
    {
        try {
            // A rejected UNIQUE write must not poison an enclosing PostgreSQL transaction.
            return $this->getConnection()->transaction(fn (): bool => parent::save($options));
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'digital_keys_key_fingerprint_unique')
                || str_contains($exception->getMessage(), 'digital_keys.key_fingerprint')) {
                throw new DuplicateDigitalKeyException;
            }

            throw $exception;
        }
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
