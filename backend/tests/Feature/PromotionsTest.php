<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromotionsTest extends TestCase
{
    public function testPublicEndpointReturnsOnlyPromotionFieldsInIdOrder(): void
    {
        config(['database.connections.promotions_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::setDefaultConnection('promotions_test');

        $migration = require database_path('migrations/2026_11_19_000002_create_promotions_table.php');
        $migration->up();

        $this->getJson('/api/promotions')->assertOk()->assertExactJson(['data' => []]);

        $first = ['id' => 2, 'title' => 'First', 'image_url' => '/first.jpg', 'link_url' => '#'];
        $last = ['id' => 10, 'title' => 'Last', 'image_url' => '/last.jpg', 'link_url' => '/games/1'];
        DB::table('promotions')->insert([$last, $first]);

        $response = $this->getJson('/api/promotions')->assertOk();
        $response->assertExactJson(['data' => [$first, $last]])->assertJsonPath('data.0.id', 2);

        $this->postJson('/api/promotions')->assertMethodNotAllowed();
    }
}
