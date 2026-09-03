<?php

namespace App\Models;

use App\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasRoles, HasOptimisticLocking, SoftDeletes;

    protected $guard_name = 'web';

    protected $fillable = [
        'employee_code',
        'name',
        'username',
        'email',
        'password',
        'user_type',
        'is_active',
        'can_login',
        'email_verified_at',
        'last_login_at',
        'last_login_ip',
        'failed_login_attempts',
        'lockout_until',
        'password_changed_at',
        'must_change_password',
        'self_service_reset_count',
        'version',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'lockout_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
            'self_service_reset_count' => 'integer',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'can_login' => 'boolean',
            'failed_login_attempts' => 'integer',
            'version' => 'integer',
        ];
    }

    public function canPerformSelfServicePasswordReset(): bool
    {
        return $this->self_service_reset_count < 3;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_code', 'employee_code');
    }

    public function projectAccesses(): HasMany
    {
        return $this->hasMany(UserProjectAccess::class, 'user_id');
    }

    public function syncJobs(): HasMany
    {
        return $this->hasMany(SyncJob::class, 'user_id');
    }

    public function ssoAuthCodes(): HasMany
    {
        return $this->hasMany(SsoAuthCode::class, 'user_id');
    }

    public function passwordResetRequests(): HasMany
    {
        return $this->hasMany(PasswordResetRequest::class, 'user_id');
    }

    public function passwordResetTokens(): HasMany
    {
        return $this->hasMany(PasswordResetToken::class, 'user_id');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_user_id');
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(UserCreationDraft::class, 'creator_user_id');
    }
}
