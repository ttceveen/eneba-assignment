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
            $table->timestampTz('confirmation_started_at')->nullable();
            $table->string('capture_id', 255)->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX payments_provider_capture_unique ON payments (provider, capture_id) WHERE capture_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE payments IN ACCESS EXCLUSIVE MODE');
        }
        if (DB::table('payments')->whereNotNull('confirmation_started_at')->orWhereNotNull('capture_id')->exists()) {
            throw new RuntimeException('Payment confirmation metadata exists; refusing to discard history.');
        }
        DB::statement('DROP INDEX payments_provider_capture_unique');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['confirmation_started_at', 'capture_id']);
        });
    }
};
