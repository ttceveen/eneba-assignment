<?php

namespace Database\Seeders;

use App\Models\DigitalKey;
use App\Models\Offer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DigitalKeySeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $keys = DigitalKey::all();
            $byOffer = $keys->groupBy('offer_id');
            $used = [];
            foreach ($keys as $key) {
                $used[DigitalKey::fingerprint($key->key_value)] = true;
            }

            foreach (Offer::orderBy('id')->get() as $offer) {
                $existing = $byOffer->get($offer->id, collect());
                if ($existing->contains(fn (DigitalKey $key): bool => $key->status !== 'available')
                    || $existing->count() > $offer->quantity) {
                    throw new RuntimeException('Key seeding requires available-only stock not exceeding offer quantity; existing stock was not overwritten.');
                }
                $count = $existing->count();
                for ($slot = 1; $count < $offer->quantity; $slot++) {
                    $value = sprintf('TEST-NOT-VALID-OFFER-%d-KEY-%d', $offer->id, $slot);
                    $hash = DigitalKey::fingerprint($value);
                    if (isset($used[$hash])) {
                        continue;
                    }
                    $offer->digitalKeys()->create(['key_value' => $value, 'status' => 'available']);
                    $used[$hash] = true;
                    $count++;
                }
            }
        });
    }
}
