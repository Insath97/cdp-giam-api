<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'base_url',
        'icon_url',
        'status',
    ];

    /**
     * Get the technical integration configuration for this project.
     */
    public function integration(): HasOne
    {
        return $this->hasOne(ProjectIntegration::class, 'project_id');
    }

    /**
     * Get the modules defined by this project.
     */
    public function modules(): HasMany
    {
        return $this->hasMany(ProjectModule::class, 'project_id');
    }

    /**
     * Get the roles defined by this project.
     */
    public function roles(): HasMany
    {
        return $this->hasMany(ProjectRole::class, 'project_id');
    }

    /**
     * Get the permission groups defined by this project.
     */
    public function permissionGroups(): HasMany
    {
        return $this->hasMany(ProjectPermissionGroup::class, 'project_id');
    }

    /**
     * Get the permissions defined by this project.
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(ProjectPermission::class, 'project_id');
    }

    /**
     * Get the user project access assignments for this project.
     */
    public function userAccesses(): HasMany
    {
        return $this->hasMany(UserProjectAccess::class, 'project_id');
    }

    /**
     * Get the provisioning sync jobs for this project.
     */
    public function syncJobs(): HasMany
    {
        return $this->hasMany(SyncJob::class, 'project_id');
    }

    /**
     * Get the SSO authorization codes generated for this project.
     */
    public function ssoAuthCodes(): HasMany
    {
        return $this->hasMany(SsoAuthCode::class, 'project_id');
    }

    /**
     * Get the audit logs recorded for this project.
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'project_id');
    }
}
