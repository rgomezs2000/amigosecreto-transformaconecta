<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPermissionModule extends Model
{
    use HasAuditFields;
    protected $table = 'user_permission_module';

    protected $fillable = [
        'user_id',
        'permission_id',
        'module_id',
        'allowed',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allowed' => 'boolean',
        ];
    }

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

