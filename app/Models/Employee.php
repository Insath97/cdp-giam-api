<?php

namespace App\Models;

use App\Traits\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, HasOptimisticLocking, SoftDeletes;

    protected $fillable = [
        'employee_code',
        'f_name',
        'l_name',
        'full_name',
        'name_with_initials',
        'employee_type',
        'id_type',
        'id_number',
        'date_of_birth',
        'email',
        'phone',
        'address_line_1',
        'city',
        'state',
        'country',
        'postal_code',
        'phone_primary',
        'phone_secondary',
        'have_whatsapp',
        'whatsapp_number',
        'start_date',
        'end_date',
        'is_active',
        'province_code',
        'zonal_code',
        'region_code',
        'branch_code',
        'department_code',
        'designation_code',
        'reporting_manager_code',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'have_whatsapp' => 'boolean',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'employee_code', 'employee_code');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(OrgProvince::class, 'province_code', 'code');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(OrgZone::class, 'zonal_code', 'code');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(OrgRegion::class, 'region_code', 'code');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(OrgBranch::class, 'branch_code', 'code');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(OrgDepartment::class, 'department_code', 'code');
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(OrgDesignation::class, 'designation_code', 'code');
    }

    public function reportingManager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_code', 'employee_code');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'reporting_manager_code', 'employee_code');
    }
}
