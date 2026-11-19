<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un deseo de la lista del participante.
 *
 * La lista es privada del dueño y de quien le regala: el amigo secreto la ve
 * en «Ver tu amigo» para elegir el obsequio, pero nunca se muestra al resto.
 */
class WishItem extends Model
{
    use HasAuditFields, SoftDeletes;
    protected $fillable = [
        'event_id',
        'user_id',
        'title',
        'description',
        'url',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}



