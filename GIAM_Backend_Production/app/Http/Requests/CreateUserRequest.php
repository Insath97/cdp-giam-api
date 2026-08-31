<?php
namespace App\Http\Requests;
use App\Models\Application; use App\Models\Permission; use App\Models\PermissionGroup; use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest; use Illuminate\Support\Facades\Auth; use Illuminate\Validation\Rule;
class CreateUserRequest extends FormRequest
{
 public function authorize():bool{return Auth::check();}
 public function rules():array{return [
  'name'=>['required','string','max:255'],'email'=>['required','email','max:255','unique:users,email'],'user_type'=>['required',Rule::in(['admin','staff'])],
  'username'=>['required_if:user_type,admin','nullable','string','max:255','unique:users,username'],'password'=>['required_if:user_type,admin','nullable','string','min:12'],
  'role'=>['nullable','string','exists:roles,name'],
  'applications'=>['nullable','array'],'applications.*.application_id'=>['required','integer','exists:applications,id'],
  'applications.*.module_ids'=>['sometimes','array'],'applications.*.module_ids.*'=>['integer','exists:modules,id'],
  'applications.*.roles'=>['sometimes','array'],'applications.*.roles.*'=>['integer','exists:roles,id'],
  'applications.*.permission_groups'=>['sometimes','array'],'applications.*.permission_groups.*'=>['integer','exists:permission_groups,id'],
  'applications.*.permissions'=>['sometimes','array'],'applications.*.permissions.*'=>['integer','exists:permissions,id'],
  'employee_type'=>['required_if:user_type,staff',Rule::in(['permanent','contract','internship','probation'])],'date_of_birth'=>['required_if:user_type,staff','nullable','date'],'employee_code'=>['required_if:user_type,staff','nullable','string','max:100','unique:employees,employee_code'],'id_type'=>['nullable',Rule::in(['nic','passport','driving_license','other'])],'id_number'=>['required_if:user_type,staff','nullable','string','max:100','unique:employees,id_number'],
  'phone_primary'=>['required_if:user_type,staff','nullable','string','max:50'],'phone_secondary'=>['nullable','string','max:50'],'phone'=>['nullable','string','max:50'],'address_line_1'=>['nullable','string','max:500'],'city'=>['nullable','string','max:100'],'state'=>['nullable','string','max:100'],'country'=>['nullable','string','max:100'],'postal_code'=>['nullable','string','max:30'],
  'branch_id'=>['nullable','integer','exists:branches,id'],'zonal_id'=>['nullable','integer','exists:zonals,id'],'region_id'=>['nullable','integer','exists:regions,id'],'province_id'=>['nullable','integer','exists:provinces,id'],'designation_id'=>['required_if:user_type,staff','nullable','integer','exists:designations,id'],'reporting_manager_id'=>['nullable','integer','exists:employees,id'],'is_active'=>['sometimes','boolean'],'can_login'=>['sometimes','boolean'],
 ];}
 public function withValidator($validator){$validator->after(function($v){if($this->filled('role')){ $role=Role::whereNull('application_id')->where('name',$this->input('role'))->exists(); if(!$role)$v->errors()->add('role','The selected role must be a GIAM-internal role.'); } foreach($this->input('applications',[]) as $i=>$a){$id=(int)($a['application_id']??0);if(!$id)continue;$current=Auth::user();if(!$current->hasRole('Super Admin')&&!$current->can('Project Access Assign')&&!$current->applications()->whereKey($id)->exists()){$v->errors()->add("applications.$i.application_id",'You are not authorized to assign this project.');continue;} foreach([['roles',Role::class],['permissions',Permission::class],['permission_groups',PermissionGroup::class]] as [$key,$model]){if(empty($a[$key]))continue;$bad=$model::whereIn('id',$a[$key])->where('application_id','!=',$id)->pluck('id');if($bad->isNotEmpty())$v->errors()->add("applications.$i.$key",'One or more selected items do not belong to the selected project.');} if(!empty($a['module_ids'])){ $bad=\App\Models\Module::whereIn('id',$a['module_ids'])->where('application_id','!=',$id)->exists(); if($bad)$v->errors()->add("applications.$i.module_ids",'One or more modules do not belong to the selected project.');}}});}
}
