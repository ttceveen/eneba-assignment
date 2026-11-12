<?php

namespace App\Http\Controllers;

use App\Http\Resources\CartOfferResource;
use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class OfferController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $raw = $request->query('ids', '');
        // Bound the raw list too, so duplicates cannot bypass the request limit.
        if (! is_string($raw) || ($raw !== '' && ! preg_match('/^[1-9][0-9]*(,[1-9][0-9]*){0,99}$/D', $raw))) {
            throw ValidationException::withMessages(['ids' => 'Use at most 100 positive integer IDs separated by commas.']);
        }
        $ids = $raw === '' ? [] : array_unique(explode(',', $raw));
        foreach ($ids as $id) {
            if (strlen($id) > strlen((string) PHP_INT_MAX) || (strlen($id) === strlen((string) PHP_INT_MAX) && strcmp($id, (string) PHP_INT_MAX) > 0)) {
                throw ValidationException::withMessages(['ids' => 'An ID exceeds the supported integer range.']);
            }
        }

        return CartOfferResource::collection(Offer::query()
            ->select(['id', 'game_id', 'seller_id', 'price', 'quantity', 'is_active'])
            ->whereIn('id', $ids)
            ->with(['game:id,name,img,is_active', 'seller:id,name,is_active'])
            ->withCount(['digitalKeys as available_keys_count' => fn ($keys) => $keys->where('status', 'available')])
            ->orderBy('id')->get());
    }
}
