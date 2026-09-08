<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GiamPermissionGroup extends Model
{
    use HasFactory;

    protected $table = 'giam_permission_groups';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'module_id',
        'code',
        'name',
        'description',
    ];

    /**
     * Get the module that owns this permission group.
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(GiamModule::class, 'module_id');
    }

    /**
     * Get the permissions belonging to this permission group.
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class, 'permission_group_id');
    }
}

