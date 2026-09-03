<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProjectRole extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'user_project_roles';

    protected $fillable = [
        'user_project_access_id',
        'project_role_id',
        'external_role_id',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    public function access(): BelongsTo
    {
        return $this->belongsTo(UserProjectAccess::class, 'user_project_access_id');
    }

    public function projectRole(): BelongsTo
    {
        return $this->belongsTo(ProjectRole::class, 'project_role_id');
    }
}
