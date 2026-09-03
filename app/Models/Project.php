<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'base_url',
        'icon_url',
        'status',
    ];

    public function integration(): HasOne
    {
        return $this->hasOne(ProjectIntegration::class, 'project_id');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(ProjectModule::class, 'project_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(ProjectRole::class, 'project_id');
    }

    public function permissionGroups(): HasMany
    {
        return $this->hasMany(ProjectPermissionGroup::class, 'project_id');
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(ProjectPermission::class, 'project_id');
    }

    public function userAccesses(): HasMany
    {
        return $this->hasMany(UserProjectAccess::class, 'project_id');
    }

    public function syncJobs(): HasMany
    {
        return $this->hasMany(SyncJob::class, 'project_id');
    }

    public function ssoAuthCodes(): HasMany
    {
        return $this->hasMany(SsoAuthCode::class, 'project_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'project_id');
    }
}
