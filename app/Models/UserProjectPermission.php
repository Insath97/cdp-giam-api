<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProjectPermission extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'user_project_permissions';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_project_access_id',
        'project_permission_id',
        'external_permission_id',
        'is_granted',
        'assigned_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_granted' => 'boolean',
            'assigned_at' => 'datetime',
        ];
    }

    /**
     * Get the user project access assignment owning this permission linkage.
     */
    public function access(): BelongsTo
    {
        return $this->belongsTo(UserProjectAccess::class, 'user_project_access_id');
    }

    /**
     * Get the project permission definition linked.
     */
    public function projectPermission(): BelongsTo
    {
        return $this->belongsTo(ProjectPermission::class, 'project_permission_id');
    }
}
