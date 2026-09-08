<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgRegion extends Model
{
    use HasFactory;

    protected $table = 'org_regions';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'zonal_code',
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
     * Get the parent zone for this region.
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(OrgZone::class, 'zonal_code', 'code');
    }

    /**
     * Get the branches within this region.
     */
    public function branches(): HasMany
    {
        return $this->hasMany(OrgBranch::class, 'region_code', 'code');
    }

    /**
     * Get the employees residing in this region.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'region_code', 'code');
    }
}
