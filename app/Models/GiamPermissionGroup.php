<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GiamPermissionGroup extends Model
{
    use HasFactory;

    protected $table = 'giam_permission_groups';

    protected $fillable = [
        'module_id',
        'code',
        'name',
        'description',
    ];

    public function module(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(GiamModule::class, 'module_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'permission_group_id');
    }
}
