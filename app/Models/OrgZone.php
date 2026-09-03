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

    protected $fillable = [
        'province_code',
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

    public function province(): BelongsTo
    {
        return $this->belongsTo(OrgProvince::class, 'province_code', 'code');
    }

    public function regions(): HasMany
    {
        return $this->hasMany(OrgRegion::class, 'zonal_code', 'code');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'zonal_code', 'code');
    }
}
