<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $table = 'chat_conversations';

    protected $fillable = [
        'name',
        'type',
        'created_by',
        'event_id',
        'assignment_id',
        'report_id',
        'is_moderated',
        'is_read_only',
        'pinned_message_id',
    ];

    protected function casts(): array
    {
        return [
            'is_moderated' => 'boolean',
            'is_read_only' => 'boolean',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_conversation_user')
            ->withPivot([
                'role',
                'last_read_at',
                'muted_at',
                'cleared_at',
                'hidden_at',
                'archived_at',
                'favorite_at',
                'pinned_at',
            ])
            ->withCasts([
                'last_read_at' => 'datetime',
                'muted_at' => 'datetime',
                'cleared_at' => 'datetime',
                'hidden_at' => 'datetime',
                'archived_at' => 'datetime',
                'favorite_at' => 'datetime',
                'pinned_at' => 'datetime',
            ])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Mensaje fijado dentro de la conversación (se muestra en el cintillo).
     */
    public function pinnedMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'pinned_message_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Reporte (ticket) al que pertenece el chat del caso.
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ChatReport::class, 'report_id');
    }

    /**
     * ¿Se puede desarchivar? Los chats de amigo secreto de un evento ya
     * terminado quedan archivados para siempre.
     */
    public function canBeUnarchived(): bool
    {
        if ($this->type !== 'secret') {
            return true;
        }

        return ! ($this->event?->status->isFinished() ?? false);
    }

    /**
     * ¿Alguien archivó esta conversación? Mientras sea así queda fuera de la
     * moderación, aunque el administrador no participe en ella.
     */
    public function isArchivedForAnyUser(): bool
    {
        return \Illuminate\Support\Facades\DB::table('chat_conversation_user')
            ->where('conversation_id', $this->id)
            ->whereNotNull('archived_at')
            ->exists();
    }

    /**
     * Vetos del grupo, incluidos los ya retirados.
     */
    public function bans(): HasMany
    {
        return $this->hasMany(GroupBan::class, 'conversation_id');
    }

    public function isGroup(): bool
    {
        return $this->type === 'group';
    }

    /**
     * Chat del evento: solo avisos de la organización, nadie escribe.
     */
    public function isEventChannel(): bool
    {
        return $this->type === 'event';
    }

    /**
     * Chat de un caso de reporte: solo el beneficiario y el administrador que
     * lo tomó. Se abre al tomar el caso y queda en solo lectura al cerrarlo.
     */
    public function isReportChat(): bool
    {
        return $this->type === 'report';
    }

    public function isReadOnly(): bool
    {
        return $this->is_read_only;
    }

    /**
     * Papel del usuario en la asignación del amigo secreto.
     *
     * `giver` es quien regala (sabe a quién le tocó); `receiver` es quien
     * recibe (habla con su amigo secreto sin saber quién es).
     */
    public function secretRoleFor(User $user): ?string
    {
        if ($this->type !== 'secret') {
            return null;
        }

        $assignment = $this->assignment;

        if ($assignment === null) {
            return null;
        }

        if ((int) $assignment->giver_id === (int) $user->id) {
            return 'giver';
        }

        if ((int) $assignment->receiver_id === (int) $user->id) {
            return 'receiver';
        }

        return null;
    }

    public function titleFor(User $user): string
    {
        if ($this->type === 'secret') {
            // Solo quien regala ve el nombre de su asignado; quien recibe
            // sigue hablando con un amigo secreto anónimo.
            if ($this->secretRoleFor($user) === 'giver') {
                $this->loadMissing('assignment.receiver');

                return 'Amigo secreto: '.($this->assignment?->receiver?->name ?? 'asignado');
            }

            return 'Amigo secreto';
        }

        if ($this->isReportChat()) {
            // El chat del caso se identifica con el número de ticket.
            return $this->name ?: 'Reporte Nº: '.($this->report_id ?? $this->id);
        }

        if ($this->isGroup()) {
            return $this->name ?: 'Grupo';
        }

        if ($this->isEventChannel()) {
            return $this->name ?: 'Evento';
        }

        $other = $this->users->firstWhere('id', '!=', $user->id);

        return $other?->name ?? ($this->name ?: 'Conversación');
    }
}
