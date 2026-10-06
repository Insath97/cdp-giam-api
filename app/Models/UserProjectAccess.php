<?php

namespace App\Models;

use App\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserProjectAccess extends Model
{
    use HasFactory, HasOptimisticLocking;

    protected $table = 'user_project_access';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'project_id',
        'status',
        'assigned_by',
        'assigned_at',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
        'version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'revoked_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    /**
     * Get the user / principal to whom this project access is granted.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the downstream project to which access is granted.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Get the user who assigned this access.
     */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Get the user who revoked this access.
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /**
     * Get the project roles assigned under this access entitlement.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            ProjectRole::class,
            'user_project_roles',
            'user_project_access_id',
            'project_role_id'
        )->withPivot(['external_role_id', 'assigned_at']);
    }

    /**
     * Get the direct project permissions assigned under this access entitlement.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            ProjectPermission::class,
            'user_project_permissions',
            'user_project_access_id',
            'project_permission_id'
        )->withPivot(['external_permission_id', 'is_granted', 'assigned_at']);
    }

    /**
     * Get the user project role pivot records.
     */
    public function userProjectRoles(): HasMany
    {
        return $this->hasMany(UserProjectRole::class, 'user_project_access_id');
    }

    /**
     * Get the user project permission pivot records.
     */
    public function userProjectPermissions(): HasMany
    {
        return $this->hasMany(UserProjectPermission::class, 'user_project_access_id');
    }
}
