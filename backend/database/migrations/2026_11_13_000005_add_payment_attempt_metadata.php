<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 128)->nullable();
            $table->string('request_fingerprint', 64)->nullable();
            $table->text('redirect_url')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->string('error_message')->nullable();
            $table->unique(['order_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE payments IN ACCESS EXCLUSIVE MODE');
        }
        if (DB::table('payments')->whereNotNull('idempotency_key')->orWhereNotNull('request_fingerprint')->orWhereNotNull('redirect_url')->orWhereNotNull('error_code')->orWhereNotNull('error_message')->exists()) {
            throw new RuntimeException('Payment metadata exists; refusing to discard history.');
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['order_id', 'idempotency_key']);
            $table->dropColumn(['idempotency_key', 'request_fingerprint', 'redirect_url', 'error_code', 'error_message']);
        });
    }
};
