<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectPermission extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'project_permission_group_id',
        'external_permission_id',
        'code',
        'name',
        'description',
        'is_active',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ProjectPermissionGroup::class, 'project_permission_group_id');
    }

    public function userProjectPermissions(): HasMany
    {
        return $this->hasMany(UserProjectPermission::class, 'project_permission_id');
    }
}
