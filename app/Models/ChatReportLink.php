<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatReportLink extends Model
{
    protected $fillable = [
        'report_id',
        'url',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ChatReport::class, 'report_id');
    }
}
