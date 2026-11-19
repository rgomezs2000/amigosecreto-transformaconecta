<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatReport extends Model
{
    use HasAuditFields, SoftDeletes;
    public const STATUS_PENDING = 'pendiente';

    public const STATUS_ATTENDING = 'atendiendo';

    public const STATUS_CLOSED = 'cerrado';

    public const TYPE_MODERATION = 'moderacion';

    public const TYPE_SUGGESTION = 'sugerencia';

    public const TYPE_HELP = 'ayuda';

    public const TYPE_SUPPORT = 'soporte';

    /** Solicitud pública de registro o gestión de cuenta (tipo «Usuarios»). */
    public const TYPE_USER_REGISTRATION = 'registro';

    /**
     * Tipos válidos y su etiqueta para la interfaz.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        self::TYPE_MODERATION => 'Moderación y contenido',
        self::TYPE_SUGGESTION => 'Sugerencia',
        self::TYPE_HELP => 'Ayuda',
        self::TYPE_SUPPORT => 'Soporte',
        self::TYPE_USER_REGISTRATION => 'Usuarios',
    ];

    protected $table = 'chat_reports';

    /**
     * Abre un caso de soporte para una cuenta bloqueada o una solicitud de
     * acceso. Lo usa el inicio de sesión cuando se agotan los intentos.
     */
    public static function openAccountRequest(User $user, string $kind, string $reason): self
    {
        $existing = static::query()
            ->where('type', self::TYPE_USER_REGISTRATION)
            ->where('status', '!=', self::STATUS_CLOSED)
            ->where('payload->action', $kind)
            ->where('payload->user_id', $user->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::create([
            'type' => self::TYPE_USER_REGISTRATION,
            'status' => self::STATUS_PENDING,
            'reporter_id' => $user->id,
            'reason' => $reason,
            'case_message' => 'La cuenta '.$user->username.' solicita '.$kind.'. '
                .'El acceso quedó bloqueado por intentos fallidos.',
            'context_label' => 'Acceso',
            'payload' => [
                'action' => $kind,
                'user_id' => (int) $user->id,
                'login' => $user->username,
            ],
        ]);
    }

    protected $fillable = [
        'type',
        'module_code',
        'context_url',
        'context_label',
        'message_id',
        'reporter_id',
        'reason',
        'case_message',
        'suggestion',
        'payload',
        'status',
        'assigned_to',
        'taken_by',
        'referred_to_id',
        'taken_at',
        'attention_observation',
        'closed_at',
        'closed_by',
        'close_observation',
        'managed_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'type' => self::TYPE_MODERATION,
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'closed_at' => 'datetime',
            'managed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Administrador que tomó y gestionó el caso originalmente.
     */
    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by');
    }

    /**
     * Último administrador al que se remitió el caso.
     */
    public function referredTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_to_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Chat del caso (ticket). Se crea cuando un administrador toma el reporte.
     */
    public function conversation(): HasOne
    {
        return $this->hasOne(Conversation::class, 'report_id');
    }

    /**
     * Remisiones del caso, en orden de llegada.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(ChatReportReferral::class, 'report_id')->orderBy('id');
    }

    /**
     * Archivos adjuntos del caso (incluida la evidencia generada).
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ChatReportAttachment::class, 'report_id')->orderBy('id');
    }

    /**
     * Enlaces de soporte del caso.
     */
    public function links(): HasMany
    {
        return $this->hasMany(ChatReportLink::class, 'report_id')->orderBy('id');
    }

    /**
     * Valoraciones de una sugerencia (seis aspectos).
     */
    public function ratings(): HasMany
    {
        return $this->hasMany(ChatReportRating::class, 'report_id')->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isAttending(): bool
    {
        return $this->status === self::STATUS_ATTENDING;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isManaged(): bool
    {
        if ($this->managed_at !== null) {
            return true;
        }

        // Las solicitudes de registro se consideran gestionadas en cuanto el
        // usuario ya existe, aunque el caso se haya creado desde el módulo de
        // Usuarios y no se haya marcado managed_at.
        if (! $this->isUserRegistration() || $this->isAccountRequest()) {
            return false;
        }

        $payload = $this->payload ?: [];
        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $username = mb_strtolower(trim((string) ($payload['username'] ?? '')));

        if ($email === '' && $username === '') {
            return false;
        }

        return User::query()
            ->where(function ($query) use ($email, $username) {
                if ($email !== '') {
                    $query->whereRaw('LOWER(email) = ?', [$email]);
                }

                if ($username !== '') {
                    $query->orWhereRaw('LOWER(username) = ?', [$username]);
                }
            })
            ->exists();
    }

    public function markManaged(): void
    {
        if ($this->managed_at === null) {
            $this->forceFill(['managed_at' => now()])->save();
        }
    }

    public function isModeration(): bool
    {
        return $this->type === self::TYPE_MODERATION;
    }

    public function isSuggestion(): bool
    {
        return $this->type === self::TYPE_SUGGESTION;
    }

    public function isHelp(): bool
    {
        return $this->type === self::TYPE_HELP;
    }

    public function isSupport(): bool
    {
        return $this->type === self::TYPE_SUPPORT;
    }

    public function isUserRegistration(): bool
    {
        return $this->type === self::TYPE_USER_REGISTRATION;
    }

    public function isAccountRequest(): bool
    {
        $payload = $this->payload ?: [];

        return in_array($payload['action'] ?? null, ['reset', 'unlock'], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ATTENDING => 'Atendiendo',
            self::STATUS_CLOSED => 'Cerrado',
            default => 'Por atender',
        };
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? self::TYPES[self::TYPE_MODERATION];
    }
}



