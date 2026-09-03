<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgBranch extends Model
{
    use HasFactory;

    protected $table = 'org_branches';

    protected $fillable = [
        'region_code',
        'code',
        'name',
        'city',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(OrgRegion::class, 'region_code', 'code');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'branch_code', 'code');
    }
}
