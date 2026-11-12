<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatBlock extends Model
{
    protected $table = 'chat_blocks';

    protected $fillable = ['blocker_id', 'blocked_id'];

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }

    public static function existsBetween(int $first, int $second): bool
    {
        return static::query()
            ->where(function ($query) use ($first, $second) {
                $query->where('blocker_id', $first)->where('blocked_id', $second);
            })
            ->orWhere(function ($query) use ($first, $second) {
                $query->where('blocker_id', $second)->where('blocked_id', $first);
            })
            ->exists();
    }
}
