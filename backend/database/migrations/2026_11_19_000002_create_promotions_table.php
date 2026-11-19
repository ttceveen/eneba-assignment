<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('image_url');
            $table->text('link_url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
