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

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'region_code',
        'code',
        'name',
        'city',
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
     * Get the parent region for this branch.
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(OrgRegion::class, 'region_code', 'code');
    }

    /**
     * Get the employees assigned to this branch.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'branch_code', 'code');
    }
}
