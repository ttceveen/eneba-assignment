<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained();
            $table->text('key_value');
            $table->string('status', 20)->default('available');
            $table->timestampTz('created_at');
            $table->timestampTz('updated_at');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE digital_keys ADD CONSTRAINT digital_keys_status_valid CHECK (status IN ('available', 'reserved', 'sold'))");
        }
        DB::statement("CREATE INDEX idx_digital_keys_available ON digital_keys (offer_id, id) WHERE status = 'available'");
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_keys');
    }
};
