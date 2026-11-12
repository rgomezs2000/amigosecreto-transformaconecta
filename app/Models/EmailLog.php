<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro SQL de cada correo que intenta enviar el sistema.
 *
 * El contenido completo (asunto, título, líneas y acción) se guarda en
 * Firestore; aquí queda el rastro consultable para contar el tráfico y
 * diagnosticar fallos sin depender de Firebase.
 */
class EmailLog extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id',
        'to_email',
        'subject',
        'status',
        'error',
        'source',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wasSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }
}
