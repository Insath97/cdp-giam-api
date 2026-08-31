<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    use HasFactory;

    protected $fillable = [
        'application_id',
        'name',
        'code',
        'description',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function permissions()
    {
        return $this->hasMany(Permission::class);
    }
}
