<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained();
            $table->foreignId('game_id')->constrained();
            $table->decimal('price', 10, 2);
            $table->integer('quantity');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->unique(['seller_id', 'game_id']);
        });

        // PostgreSQL CHECK constraints are covered by the isolated PostgreSQL suite.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_price_nonnegative CHECK (price >= 0)');
            DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_quantity_nonnegative CHECK (quantity >= 0)');
        }
        DB::statement('CREATE INDEX idx_offers_available_game_price ON offers (game_id, price) WHERE is_active = TRUE AND quantity > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
