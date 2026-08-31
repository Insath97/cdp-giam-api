<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFieldAccessRule extends Model
{
    protected $fillable = [
        'user_id',
        'application_id',
        'allowed_fields',
    ];

    protected $casts = [
        'allowed_fields' => 'json',
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
