<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgProvince extends Model
{
    use HasFactory;

    protected $table = 'org_provinces';

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

    public function zones(): HasMany
    {
        return $this->hasMany(OrgZone::class, 'province_code', 'code');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'province_code', 'code');
    }
}
