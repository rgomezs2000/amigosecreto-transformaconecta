<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatReportRating extends Model
{
    public const ASPECTS = [
        'general' => 'General',
        'funcionalidad' => 'Funcionalidad',
        'dinamica' => 'Dinámica',
        'soporte' => 'Soporte',
        'amigabilidad' => 'Amigabilidad',
        'comunicacion' => 'Comunicación',
    ];

    public const LABELS = [
        1 => 'Deficiente',
        2 => 'Aceptable',
        3 => 'Regular',
        4 => 'Bueno',
        5 => 'Excelente',
    ];

    protected $fillable = [
        'report_id',
        'aspect',
        'score',
    ];

    protected $casts = [
        'score' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ChatReport::class, 'report_id');
    }

    public function aspectLabel(): string
    {
        return self::ASPECTS[$this->aspect] ?? $this->aspect;
    }

    public function qualitativeLabel(): string
    {
        return self::LABELS[$this->score] ?? 'Sin valorar';
    }
}
