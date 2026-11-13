<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No existing key is decrypted or updated by this migration.
        if (DB::table('digital_keys')->where('status', '<>', 'available')->exists()) {
            throw new RuntimeException('Existing assigned keys require an explicit mapping before checkout migration.');
        }
        Schema::table('digital_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('order_item_id')->nullable();
            $table->timestampTz('reserved_until')->nullable();
            $table->string('key_fingerprint', 64)->nullable()->unique();
            $table->foreign(['order_item_id', 'offer_id'], 'digital_keys_order_item_offer_foreign')
                ->references(['id', 'offer_id'])->on('order_items');
            $table->index(['order_item_id', 'offer_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE digital_keys ADD CONSTRAINT digital_keys_assignment_valid CHECK ((status = 'available' AND order_item_id IS NULL AND reserved_until IS NULL) OR (status = 'reserved' AND order_item_id IS NOT NULL AND reserved_until IS NOT NULL) OR (status = 'sold' AND order_item_id IS NOT NULL AND reserved_until IS NULL))");
            DB::statement("ALTER TABLE digital_keys ADD CONSTRAINT digital_keys_fingerprint_valid CHECK (key_fingerprint IS NULL OR key_fingerprint ~ '^[0-9a-f]{64}$')");
        }
        DB::statement("CREATE INDEX idx_digital_keys_expired_reservations ON digital_keys (reserved_until) WHERE status = 'reserved'");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE digital_keys IN ACCESS EXCLUSIVE MODE');
        }
        if (DB::table('digital_keys')->whereNotNull('order_item_id')->orWhereNotNull('reserved_until')->orWhereNotNull('key_fingerprint')->exists()) {
            throw new RuntimeException('Key assignments or fingerprints exist; refusing a destructive rollback.');
        }
        DB::statement('DROP INDEX idx_digital_keys_expired_reservations');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE digital_keys DROP CONSTRAINT digital_keys_assignment_valid');
            DB::statement('ALTER TABLE digital_keys DROP CONSTRAINT digital_keys_fingerprint_valid');
        }
        Schema::table('digital_keys', function (Blueprint $table) {
            $table->dropForeign('digital_keys_order_item_offer_foreign');
            $table->dropIndex(['order_item_id', 'offer_id']);
            $table->dropUnique(['key_fingerprint']);
            $table->dropColumn(['order_item_id', 'reserved_until', 'key_fingerprint']);
        });
    }
};
