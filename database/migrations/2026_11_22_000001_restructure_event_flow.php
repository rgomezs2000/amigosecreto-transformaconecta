<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reestructuración del flujo principal:
 *
 *  - El evento gana el registro del envío de invitaciones y de su finalización.
 *  - La participación guarda la invitación personal (token), la respuesta y el
 *    motivo del rechazo, retiro, expulsión o descarte.
 *  - La lista de deseos pasa de un texto único a una lista de deseos con
 *    varios elementos, cada uno con su ficha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('invitations_sent_at')->nullable()->after('postponed_at');
            $table->dateTime('finalized_at')->nullable()->after('invitations_sent_at');
        });

        Schema::table('event_participants', function (Blueprint $table) {
            $table->string('invitation_token', 64)->nullable()->unique()->after('notes');
            $table->dateTime('invited_at')->nullable()->after('invitation_token');
            $table->dateTime('responded_at')->nullable()->after('invited_at');
            $table->text('status_reason')->nullable()->after('responded_at');
            $table->dateTime('status_changed_at')->nullable()->after('status_reason');

            $table->index(['event_id', 'status']);
        });

        Schema::create('wish_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('url', 255)->nullable();
            $table->timestamps();

            $table->index(['event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wish_items');

        Schema::table('event_participants', function (Blueprint $table) {
            $table->dropIndex(['event_id', 'status']);
            $table->dropUnique(['invitation_token']);
            $table->dropColumn([
                'invitation_token',
                'invited_at',
                'responded_at',
                'status_reason',
                'status_changed_at',
            ]);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['invitations_sent_at', 'finalized_at']);
        });
    }
};
