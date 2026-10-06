<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgZone extends Model
{
    use HasFactory;

    protected $table = 'org_zones';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'province_code',
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
     * Get the parent province for this zone.
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(OrgProvince::class, 'province_code', 'code');
    }

    /**
     * Get the regions within this zone.
     */
    public function regions(): HasMany
    {
        return $this->hasMany(OrgRegion::class, 'zonal_code', 'code');
    }

    /**
     * Get the employees residing in this zone.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'zonal_code', 'code');
    }
}
