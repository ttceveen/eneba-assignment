<?php

namespace Tests\Unit;

use App\Http\Resources\OfferResource;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class OfferResourceTest extends TestCase
{
    public function test_public_offer_includes_platform_without_changing_price_or_quantity(): void
    {
        $offer = (object) [
            'id' => 23,
            'seller' => (object) ['id' => 2, 'name' => 'GameHub'],
            'platform' => 'Nintendo',
            'price' => '61.24',
            'quantity' => '4',
        ];

        $this->assertSame([
            'id' => 23,
            'seller' => ['id' => 2, 'name' => 'GameHub'],
            'platform' => 'Nintendo',
            'price' => '61.24',
            'quantity' => 4,
        ], (new OfferResource($offer))->toArray(Request::create('/api/games/8')));
    }
}
