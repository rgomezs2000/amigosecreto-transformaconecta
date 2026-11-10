<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeGift extends Model
{
    protected $fillable = [
        'assignment_id',
        'product',
        'description',
        'value',
        'registered_at',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'registered_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }
}
