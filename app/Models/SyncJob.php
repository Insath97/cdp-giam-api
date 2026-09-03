<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'idempotency_key',
        'user_id',
        'project_id',
        'operation',
        'payload',
        'status',
        'attempt_count',
        'max_attempts',
        'last_error',
        'response_body',
        'http_status_code',
        'next_retry_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'http_status_code' => 'integer',
            'next_retry_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
