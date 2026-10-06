<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectPermissionGroup extends Model
{
    use HasFactory;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'project_id',
        'project_module_id',
        'external_group_id',
        'code',
        'name',
        'description',
        'is_active',
        'last_synced_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Get the project that defines this permission group.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Get the module to which this permission group belongs.
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(ProjectModule::class, 'project_module_id');
    }

    /**
     * Get the permissions under this permission group.
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(ProjectPermission::class, 'project_permission_group_id');
    }
}
