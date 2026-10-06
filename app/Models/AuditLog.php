<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AuditLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'actor_user_id',
        'action',
        'entity_type',
        'entity_id',
        'project_id',
        'ip_address',
        'user_agent',
        'request_id',
        'before_data',
        'after_data',
        'status',
        'metadata',
        'created_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Enforce strict database immutability and tamper resistance at the model level.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('AuditLog records are strictly immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException('AuditLog records are tamper-evident and cannot be deleted.');
        });
    }

    /**
     * Get the user who performed the audited action.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * Get the project associated with the audited action.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
