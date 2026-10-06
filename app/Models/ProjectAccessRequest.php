<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectAccessRequest extends Model
{
    use HasFactory;

    protected $table = 'project_access_requests';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'requested_project_name',
        'nature_of_role',
        'status',
        'submitted_by_user_id',
        'reviewed_by_user_id',
        'reviewed_at',
        'fulfilled_at',
        'resolved_project_id',
        'user_project_access_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    /**
     * Get the employee profile for whom access was requested.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Get the user who submitted this access request.
     */
    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    /**
     * Get the user who reviewed this access request.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /**
     * Get the resolved project when fulfilled.
     */
    public function resolvedProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'resolved_project_id');
    }

    /**
     * Get the fulfilled user project access record.
     */
    public function userProjectAccess(): BelongsTo
    {
        return $this->belongsTo(UserProjectAccess::class, 'user_project_access_id');
    }
}
