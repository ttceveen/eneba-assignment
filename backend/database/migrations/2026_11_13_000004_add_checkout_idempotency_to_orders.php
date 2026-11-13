<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Nullable only for orders predating idempotent checkout.
            $table->string('idempotency_key', 128)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->unique(['user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE orders IN ACCESS EXCLUSIVE MODE');
        }
        if (DB::table('orders')->whereNotNull('idempotency_key')->exists()) {
            throw new RuntimeException('Idempotent checkout history exists; refusing to discard request keys.');
        }
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'request_fingerprint']);
        });
    }
};
