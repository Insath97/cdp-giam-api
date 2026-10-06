<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgDepartment extends Model
{
    use HasFactory;

    protected $table = 'org_departments';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the designations within this department.
     */
    public function designations(): HasMany
    {
        return $this->hasMany(OrgDesignation::class, 'department_code', 'code');
    }

    /**
     * Get the employees belonging to this department.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_code', 'code');
    }
}
