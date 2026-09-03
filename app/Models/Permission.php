<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $fillable = [
        'name',
        'guard_name',
        'permission_group_id',
        'description',
    ];

    public function permissionGroup(): BelongsTo
    {
        return $this->belongsTo(GiamPermissionGroup::class, 'permission_group_id');
    }
}
