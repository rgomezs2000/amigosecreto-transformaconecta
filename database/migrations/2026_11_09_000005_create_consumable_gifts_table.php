<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumable_gifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->string('description', 150);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('value', 10, 2)->nullable();
            $table->date('given_on')->nullable();
            $table->timestamps();

            $table->index('given_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consumable_gifts');
    }
};
