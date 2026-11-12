<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Bitácora de cambios de un grupo: quién hizo qué, sobre quién y cuándo.
 */
class ChatGroupAudit extends Model
{
    public $timestamps = false;

    protected $table = 'chat_group_audits';

    protected $fillable = [
        'conversation_id',
        'actor_id',
        'target_user_id',
        'action',
        'status',
        'details',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public static function log(
        Conversation $conversation,
        ?User $actor,
        string $action,
        ?User $target = null,
        ?string $status = null,
        ?string $details = null
    ): self {
        return self::query()->create([
            'conversation_id' => $conversation->id,
            'actor_id' => $actor?->id,
            'target_user_id' => $target?->id,
            'action' => $action,
            'status' => $status,
            'details' => $details,
        ]);
    }

    public function label(): string
    {
        return match ($this->action) {
            'grupo_creado' => 'Grupo creado',
            'invitacion_enviada' => 'Invitación enviada',
            'miembro_agregado' => 'Miembro agregado',
            'invitacion_rechazada' => 'Invitación rechazada',
            'suspension' => 'Miembro suspendido',
            'baneo' => 'Miembro vetado',
            'baneo_retirado' => 'Veto retirado',
            'expulsion' => 'Miembro expulsado',
            'salida' => 'Miembro salió del grupo',
            'grupo_eliminado' => 'Grupo eliminado',
            'mensaje_eliminado' => 'Mensaje eliminado',
            'vaciar_sala' => 'Sala vaciada',
            default => Str::headline($this->action),
        };
    }
}
