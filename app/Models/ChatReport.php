<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatReport extends Model
{
    public const STATUS_PENDING = 'pendiente';

    public const STATUS_ATTENDING = 'atendiendo';

    public const STATUS_CLOSED = 'cerrado';

    public const TYPE_MODERATION = 'moderacion';

    public const TYPE_SUGGESTION = 'sugerencia';

    public const TYPE_HELP = 'ayuda';

    public const TYPE_SUPPORT = 'soporte';

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
    ];

    protected $table = 'chat_reports';

    protected $fillable = [
        'type',
        'module_code',
        'context_url',
        'context_label',
        'message_id',
        'reporter_id',
        'reason',
        'status',
        'assigned_to',
        'taken_by',
        'referred_to_id',
        'taken_at',
        'attention_observation',
        'closed_at',
        'closed_by',
        'close_observation',
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
