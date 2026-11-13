<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL is the application database; SQLite does not verify this constraint.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        // Laravel runs PostgreSQL migrations in transactions. Block new NULL writes
        // between preflight and ALTER, without changing ciphertext or existing data.
        DB::statement('LOCK TABLE digital_keys IN ACCESS EXCLUSIVE MODE');
        if (DB::table('digital_keys')->whereNull('key_fingerprint')->exists()) {
            throw new RuntimeException('Fingerprint backfill is incomplete; NOT NULL was not applied.');
        }
        DB::statement('ALTER TABLE digital_keys ALTER COLUMN key_fingerprint SET NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE digital_keys ALTER COLUMN key_fingerprint DROP NOT NULL');
        }
    }
};
