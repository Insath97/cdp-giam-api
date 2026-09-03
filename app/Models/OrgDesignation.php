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

    protected $fillable = [
        'department_code',
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

    public function department(): BelongsTo
    {
        return $this->belongsTo(OrgDepartment::class, 'department_code', 'code');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'designation_code', 'code');
    }
}
