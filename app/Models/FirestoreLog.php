<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro SQL de cada escritura enviada al espejo NoSQL (Firestore).
 *
 * Guarda el mismo JSON que viaja a Firestore, el usuario que originó la
 * transacción y los usuarios relacionados con los documentos.
 */
class FirestoreLog extends Model
{
    use HasAuditFields;

    protected $table = 'firestore_logs';

    protected $fillable = [
        'user_id',
        'user_ids',
        'action',
        'collection',
        'document_id',
        'documents',
        'payload',
        'status',
        'error',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'user_ids' => 'array',
        'payload' => 'array',
        'documents' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
