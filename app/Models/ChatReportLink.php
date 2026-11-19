<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatReportLink extends Model
{
    use HasAuditFields, SoftDeletes;
    protected $fillable = [
        'report_id',
        'url',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(ChatReport::class, 'report_id');
    }
}



