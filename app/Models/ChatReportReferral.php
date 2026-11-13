<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remisión de un caso de reporte a otro administrador.
 *
 * La observación explica el motivo de la remisión y queda como historial del
 * caso.
 */
class ChatReportReferral extends Model
{
    protected $table = 'chat_report_referrals';

    protected $fillable = [
        'report_id',
        'from_admin_id',
        'to_admin_id',
        'observation',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ChatReport::class, 'report_id');
    }

    public function fromAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_admin_id');
    }

    public function toAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_admin_id');
    }
}
