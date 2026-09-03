<?php

namespace Database\Seeders;

use App\Models\OrgBranch;
use App\Models\OrgDepartment;
use App\Models\OrgDesignation;
use App\Models\OrgProvince;
use App\Models\OrgRegion;
use App\Models\OrgZone;
use Illuminate\Database\Seeder;

class OrgStructureSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Provinces
        $provinces = [
            ['code' => 'WP', 'name' => 'Western Province'],
            ['code' => 'CP', 'name' => 'Central Province'],
            ['code' => 'SP', 'name' => 'Southern Province'],
        ];
        foreach ($provinces as $p) {
            OrgProvince::updateOrCreate(['code' => $p['code']], $p);
        }

        // 2. Zones
        $zones = [
            ['province_code' => 'WP', 'code' => 'Z01', 'name' => 'Colombo Zone'],
            ['province_code' => 'CP', 'code' => 'Z02', 'name' => 'Kandy Zone'],
            ['province_code' => 'SP', 'code' => 'Z03', 'name' => 'Galle Zone'],
        ];
        foreach ($zones as $z) {
            OrgZone::updateOrCreate(['code' => $z['code']], $z);
        }

        // 3. Regions
        $regions = [
            ['zonal_code' => 'Z01', 'code' => 'R01', 'name' => 'Colombo Central Region'],
            ['zonal_code' => 'Z02', 'code' => 'R02', 'name' => 'Kandy City Region'],
            ['zonal_code' => 'Z03', 'code' => 'R03', 'name' => 'Galle Fort Region'],
        ];
        foreach ($regions as $r) {
            OrgRegion::updateOrCreate(['code' => $r['code']], $r);
        }

        // 4. Branches
        $branches = [
            ['region_code' => 'R01', 'code' => 'BR01', 'name' => 'Head Office Colombo', 'city' => 'Colombo'],
            ['region_code' => 'R02', 'code' => 'BR02', 'name' => 'Kandy Branch', 'city' => 'Kandy'],
            ['region_code' => 'R03', 'code' => 'BR03', 'name' => 'Galle Branch', 'city' => 'Galle'],
        ];
        foreach ($branches as $b) {
            OrgBranch::updateOrCreate(['code' => $b['code']], $b);
        }

        // 5. Departments
        $departments = [
            ['code' => 'DEP01', 'name' => 'Human Resources'],
            ['code' => 'DEP02', 'name' => 'Logistics & Operations'],
            ['code' => 'DEP03', 'name' => 'Information Technology'],
            ['code' => 'DEP04', 'name' => 'Finance & Accounting'],
        ];
        foreach ($departments as $d) {
            OrgDepartment::updateOrCreate(['code' => $d['code']], $d);
        }

        // 6. Designations
        $designations = [
            ['department_code' => 'DEP01', 'code' => 'DES01', 'name' => 'Senior Software Engineer'],
            ['department_code' => 'DEP01', 'code' => 'DES02', 'name' => 'HR Manager'],
            ['department_code' => 'DEP01', 'code' => 'DES03', 'name' => 'HR Executive'],
            ['department_code' => 'DEP02', 'code' => 'DES04', 'name' => 'Logistics Coordinator'],
            ['department_code' => 'DEP02', 'code' => 'DES05', 'name' => 'Fleet Operations Lead'],
            ['department_code' => 'DEP03', 'code' => 'DES06', 'name' => 'Systems Administrator'],
            ['department_code' => 'DEP04', 'code' => 'DES07', 'name' => 'Financial Controller'],
        ];
        foreach ($designations as $des) {
            OrgDesignation::updateOrCreate(['code' => $des['code']], $des);
        }
    }
}
