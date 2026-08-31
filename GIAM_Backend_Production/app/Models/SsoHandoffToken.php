<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SsoHandoffToken extends Model
{
    protected $fillable = [
        'user_id',
        'application_id',
        'token',
        'expires_at',
        'consumed',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
