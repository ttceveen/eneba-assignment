<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Check before changing schema: never silently truncate existing names.
        $names = [];
        foreach (DB::table('users')->orderBy('id')->cursor() as $user) {
            [$first, $last] = array_pad(explode(' ', trim($user->name), 2), 2, '');
            if (mb_strlen($first) > 100 || mb_strlen($last) > 100) {
                throw new RuntimeException('An existing user name needs manual mapping before migration.');
            }
            $names[$user->id] = ['first_name' => $first, 'last_name' => $last];
        }
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->default('');
            $table->string('last_name', 100)->default('');
            $table->string('status', 20)->default('active');
        });
        foreach ($names as $id => $name) {
            DB::table('users')->where('id', $id)->update($name);
        }
        DB::table('users')->whereNull('created_at')->update(['created_at' => now()]);
        DB::table('users')->whereNull('updated_at')->update(['updated_at' => now()]);
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 100)->default(null)->change();
            $table->string('last_name', 100)->default(null)->change();
            $table->timestamp('created_at')->nullable(false)->change();
            $table->timestamp('updated_at')->nullable(false)->change();
            $table->dropColumn(['name', 'email_verified_at', 'remember_token']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_valid CHECK (status IN ('active', 'blocked'))");
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name')->default('');
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
        });
        foreach (DB::table('users')->orderBy('id')->cursor() as $user) {
            DB::table('users')->where('id', $user->id)->update(['name' => trim($user->first_name.' '.$user->last_name)]);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'status']);
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
        });
    }
};
