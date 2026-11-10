<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumableGift extends Model
{
    protected $fillable = [
        'assignment_id',
        'description',
        'quantity',
        'value',
        'given_on',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'value' => 'decimal:2',
            'given_on' => 'date',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }
}
