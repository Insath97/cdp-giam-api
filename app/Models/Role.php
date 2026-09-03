<?php

namespace App\Models;

use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    protected $fillable = [
        'name',
        'guard_name',
        'description',
        'is_system_reserved',
    ];

    protected function casts(): array
    {
        return [
            'is_system_reserved' => 'boolean',
        ];
    }
}
