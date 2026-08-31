<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProjectSyncLog extends Model
{
    protected $fillable = [
        'application_id',
        'user_id',
        'status',
        'type',
        'payload',
        'response',
        'error_message',
    ];

    protected $casts = [
        'payload' => 'json',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
