<?php

namespace App\Models;

use Spatie\Permission\Models\Permission as SpatiePermission;

class Permission extends SpatiePermission
{
    protected $fillable = [
        'name',
        'guard_name',
        'group_name',
        'module_id',
        'application_id',
        'source',
        'synced_at',
    ];

    protected $casts = ['synced_at'=>'datetime'];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
