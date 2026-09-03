<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class GiamModule extends Model
{
    use HasFactory;

    protected $table = 'giam_modules';

    protected $fillable = [
        'code',
        'name',
        'description',
        'icon',
        'order_index',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order_index' => 'integer',
        ];
    }

    public function permissionGroups(): HasMany
    {
        return $this->hasMany(GiamPermissionGroup::class, 'module_id');
    }

    public function permissions(): HasManyThrough
    {
        return $this->hasManyThrough(Permission::class, GiamPermissionGroup::class, 'module_id', 'permission_group_id');
    }
}
