<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrgProvince extends Model
{
    use HasFactory;

    protected $table = 'org_provinces';

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
     * Get the organizational zones within this province.
     */
    public function zones(): HasMany
    {
        return $this->hasMany(OrgZone::class, 'province_code', 'code');
    }

    /**
     * Get the employees residing in this province.
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'province_code', 'code');
    }
}
