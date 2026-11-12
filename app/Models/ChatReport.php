<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatReport extends Model
{
    public const STATUS_PENDING = 'pendiente';

    public const STATUS_ATTENDING = 'atendiendo';

    public const STATUS_CLOSED = 'cerrado';

    protected $table = 'chat_reports';

    protected $fillable = [
        'message_id',
        'reporter_id',
        'reason',
        'status',
        'assigned_to',
        'taken_at',
        'attention_observation',
        'closed_at',
        'closed_by',
        'close_observation',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
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

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
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

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_ATTENDING => 'Atendiendo',
            self::STATUS_CLOSED => 'Cerrado',
            default => 'Por atender',
        };
    }
}
