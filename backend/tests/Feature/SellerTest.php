<?php

namespace Tests\Feature;

use App\Models\Seller;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SellerTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_can_be_created_with_mass_assignment(): void
    {
        $seller = Seller::create(['name' => 'Example seller', 'is_active' => true]);

        $this->assertIsInt($seller->id);
        $this->assertSame('Example seller', $seller->fresh()->name);
        $this->assertTrue($seller->fresh()->is_active);
        $this->assertDatabaseCount('sellers', 1);
    }

    public function test_database_requires_name(): void
    {
        $this->expectException(QueryException::class);
        Seller::create(['is_active' => true]);
    }

    public function test_active_defaults_to_true_in_model_and_database(): void
    {
        $seller = Seller::create(['name' => 'Default seller']);
        $this->assertTrue($seller->is_active);
        $this->assertTrue($seller->fresh()->is_active);

        // Bypass model defaults to verify the actual database default as well.
        $id = DB::table('sellers')->insertGetId([
            'name' => 'Database default seller', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertTrue(Seller::findOrFail($id)->is_active);
    }

    public function test_seller_can_be_deactivated(): void
    {
        $seller = Seller::create(['name' => 'Active seller']);
        $seller->update(['is_active' => false]);

        $this->assertFalse($seller->fresh()->is_active);
        $this->assertSame('Active seller', $seller->fresh()->name);
    }

    public function test_timestamps_are_managed_automatically(): void
    {
        $this->travelTo(now()->startOfSecond());
        $seller = Seller::create(['name' => 'Timestamp seller']);
        $created = $seller->created_at->copy();

        $this->assertInstanceOf(Carbon::class, $seller->created_at);
        $this->assertInstanceOf(Carbon::class, $seller->updated_at);
        $this->assertTrue($seller->created_at->equalTo(now()));
        $this->assertTrue($seller->updated_at->equalTo(now()));

        $this->travel(1)->minute();
        $seller->update(['name' => 'Updated seller']);
        $seller->refresh();
        $this->assertTrue($seller->created_at->equalTo($created));
        $this->assertTrue($seller->updated_at->equalTo(now()));
        $this->assertTrue($seller->updated_at->greaterThan($seller->created_at));
    }

    public function test_timestamp_columns_are_not_nullable(): void
    {
        $columns = collect(Schema::getColumns('sellers'))->keyBy('name');
        $this->assertFalse($columns['created_at']['nullable']);
        $this->assertFalse($columns['updated_at']['nullable']);
    }

    public function test_migration_down_drops_only_sellers_and_can_be_reapplied(): void
    {
        $migration = require database_path('migrations/2026_11_12_000004_create_sellers_table.php');
        $this->assertTrue(Schema::hasTable('sellers'));
        $migration->down();
        $this->assertFalse(Schema::hasTable('sellers'));
        $this->assertTrue(Schema::hasTable('games'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('sellers'));
    }
}
