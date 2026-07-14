<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'guard_name',
        'application_id',
        'is_protected',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
