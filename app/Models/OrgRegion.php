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

    protected $fillable = [
        'zonal_code',
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

    public function zone(): BelongsTo
    {
        return $this->belongsTo(OrgZone::class, 'zonal_code', 'code');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(OrgBranch::class, 'region_code', 'code');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'region_code', 'code');
    }
}
