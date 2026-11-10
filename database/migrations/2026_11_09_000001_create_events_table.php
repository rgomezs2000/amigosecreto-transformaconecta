<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedSmallInteger('year')->unique();
            $table->string('status', 20)->default('draft')->index();

            $table->dateTime('registration_opens_at')->nullable();
            $table->dateTime('registration_closes_at')->nullable();
            $table->dateTime('dynamic_starts_at')->nullable();
            $table->dateTime('exchange_at')->nullable();

            $table->decimal('min_value', 10, 2)->nullable();
            $table->decimal('max_value', 10, 2)->nullable();
            $table->decimal('consumable_value_cap', 10, 2)->nullable();
            $table->boolean('reveal_shows_value')->default(false);
            $table->boolean('allow_mutual_pairs')->default(false);
            $table->char('currency', 3)->default('USD');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
