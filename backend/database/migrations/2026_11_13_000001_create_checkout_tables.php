<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('status', 30);
            $table->decimal('total_amount', 12, 2);
            $table->string('currency', 3)->default('EUR');
            $table->timestampTz('reserved_until');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('offer_id')->constrained();
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->index('order_id');
            $table->index('offer_id');
            // Referenced by the composite FK on digital_keys: same item AND offer.
            $table->unique(['id', 'offer_id']);
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->string('provider', 50);
            $table->string('status', 30);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('external_id')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
            $table->index(['order_id', 'created_at']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_valid CHECK (status IN ('pending_payment', 'paid', 'expired', 'cancelled'))");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_amount_nonnegative CHECK (total_amount >= 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_price_nonnegative CHECK (unit_price >= 0)');
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('pending', 'succeeded', 'failed', 'cancelled'))");
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_nonnegative CHECK (amount >= 0)');
        }
        DB::statement("CREATE INDEX idx_orders_expired_reservations ON orders (reserved_until) WHERE status = 'pending_payment'");
        DB::statement('CREATE UNIQUE INDEX payments_provider_external_unique ON payments (provider, external_id) WHERE external_id IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX payments_one_pending_per_order ON payments (order_id) WHERE status = 'pending'");
        DB::statement("CREATE UNIQUE INDEX payments_one_succeeded_per_order ON payments (order_id) WHERE status = 'succeeded'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE orders, order_items, payments IN ACCESS EXCLUSIVE MODE');
        }
        if (DB::table('payments')->exists() || DB::table('order_items')->exists() || DB::table('orders')->exists()) {
            throw new RuntimeException('Checkout history exists; refusing a destructive rollback.');
        }
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
