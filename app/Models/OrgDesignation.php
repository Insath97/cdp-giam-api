<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgDesignation extends Model
{
    use HasFactory;

    protected $table = 'org_designations';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'department_code',
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
     * Get the department that owns this designation.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(OrgDepartment::class, 'department_code', 'code');
    }

    /**
     * Get the employees with this designation.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'designation_code', 'code');
    }
}

