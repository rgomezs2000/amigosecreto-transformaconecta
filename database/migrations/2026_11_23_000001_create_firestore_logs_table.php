<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firestore_logs', function (Blueprint $table) {
            $table->id();

            // Usuario que originó la transacción (si existe sesión autenticada).
            $table->unsignedBigInteger('user_id')->nullable()->index();

            // Todos los usuarios relacionados: actor + usuarios del payload.
            $table->json('user_ids')->nullable();

            $table->string('action', 32);
            $table->string('collection', 80)->nullable();
            $table->string('document_id', 160)->nullable();
            $table->unsignedInteger('documents')->default(1);

            // Mismo JSON que se envía a Firestore.
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('ok');
            $table->text('error')->nullable();

            $table->timestamps();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();

            $table->index(['collection', 'document_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firestore_logs');
    }
};
