<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgDepartment extends Model
{
    use HasFactory;

    protected $table = 'org_departments';

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function designations(): HasMany
    {
        return $this->hasMany(OrgDesignation::class, 'department_code', 'code');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_code', 'code');
    }
}
