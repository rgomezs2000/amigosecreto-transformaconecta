<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfilePermissionModule extends Model
{
    use HasAuditFields;
    protected $table = 'profile_permission_module';

    protected $fillable = [
        'profile_id',
        'permission_id',
        'module_id',
    ];

    public $timestamps = false;

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}

