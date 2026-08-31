<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Mail\UserCreateMail;
use App\Models\Employee;
use App\Models\User;
use App\Models\Application;
use App\Services\ProjectSyncService;
use App\Traits\ActivityLogTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use App\Jobs\SyncUserToProjectJob;
use Illuminate\Support\Facades\DB;

class UserController extends Controller implements HasMiddleware
{
    use ActivityLogTrait;

    public static function middleware(): array
    {
        return [
            new Middleware('permission:User Index', only: ['index', 'show']),
            new Middleware('permission:User Create', only: ['store']),
            new Middleware('permission:User Update', only: ['update']),
            new Middleware('permission:User Delete', only: ['destroy']),
            new Middleware('permission:User Toggle Status', only: ['toggleStatus']),
        ];
    }

    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $query = User::with(['employee.branch', 'employee.zonal', 'employee.region', 'employee.province', 'employee.reportingManager.user', 'employee.designation', 'roles', 'permissions', 'applications']);

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function (Builder $builder) use ($search) {
                    $builder->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            }

            if ($request->has('user_type')) {
                $query->where('user_type', $request->user_type);
            }

            if ($request->has('role')) {
                $roleName = $request->role;
                $query->whereHas('roles', function ($q) use ($roleName) {
                    $q->where('name', $roleName);
                });
            }

            if ($request->has('branch_id')) {
                $query->whereHas('employee', function ($q) use ($request) {
                    $q->where('branch_id', $request->branch_id);
                });
            }

            if ($request->has('is_active')) {
                $request->boolean('is_active') ? $query->where('is_active', true) : $query->where('is_active', false);
            }

            $users = $query->paginate($perPage);

            // Transform data to hide pivot
            $users->getCollection()->transform(function ($user) {
                $userData = $user->toArray();
                if (isset($userData['roles'])) {
                    foreach ($userData['roles'] as &$role) {
                        unset($role['pivot']);
                    }
                }
                return $userData;
            });
            return response()->json([
                'status' => 'success',
                'message' => 'Users retrieved successfully',
                'data' => $users
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve users',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function store(CreateUserRequest $request)
    {
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $currentUser = auth("api")->user();
            $data = $request->validated();

            // Restrict admin user creation to Super Admins only
            if ($data['user_type'] === 'admin') {
                if (!$currentUser || !$currentUser->hasRole('Super Admin')) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Only Super Admin can create admin users'
                    ], 403);
                }
            }

            // Create Employee first if user_type is staff
            if ($data['user_type'] === 'staff') {
                $nameParts=preg_split('/\s+/',trim($data['name']));
                $fName=$nameParts[0]??$data['name']; $lName=count($nameParts)>1?implode(' ',array_slice($nameParts,1)):'';
                $employeeData = [
                    'f_name'=>$fName,'l_name'=>$lName,'full_name'=>$data['name'],'name_with_initials'=>$data['name'],
                    'employee_type'=>$data['employee_type'], 'date_of_birth'=>$data['date_of_birth'],
                    'employee_code' => $data['employee_code'],
                    'id_type' => $data['id_type'] ?? null,
                    'id_number' => $data['id_number'],
                    'phone' => $data['phone'] ?? null,
                    'phone_primary' => $data['phone_primary'] ?? null,
                    'phone_secondary' => $data['phone_secondary'] ?? null,
                    'address_line_1' => $data['address_line_1'] ?? null,
                    'city' => $data['city'] ?? null,
                    'state' => $data['state'] ?? null,
                    'country' => $data['country'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'branch_id' => $data['branch_id'] ?? null,
                    'zonal_id' => $data['zonal_id'] ?? null,
                    'region_id' => $data['region_id'] ?? null,
                    'province_id' => $data['province_id'] ?? null,
                    'designation_id' => $data['designation_id'] ?? null,
                    'reporting_manager_id' => $data['reporting_manager_id'] ?? null,
                ];

                $employee = Employee::create($employeeData);

                // Set staff credentials automatically to id_number
                $data['employee_id'] = $employee->id;
                $data['username'] = $data['id_number'];
                $data['password'] = Hash::make($data['id_number']);
            } else {
                // For admin users
                $data['employee_id'] = null;
                $data['password'] = Hash::make($data['password']);
            }

            $user = User::create($data);

            // Assign Role
            if (isset($data['role'])) {
                $user->assignRole($data['role']);
            }

            // Assign applications and application-wise roles/permissions
            if (isset($data['applications'])) {
                $oldAppIds = $user->applications()->pluck('applications.id')->all();
                $appUserMappings = array_map(fn($x)=>(int)$x['application_id'], $data['applications']);
                $removedAppIds = array_diff($oldAppIds, $appUserMappings);
                $user->applications()->sync($appUserMappings);
                foreach ($removedAppIds as $removedAppId) {
                    $user->syncRolesForApplication([], (int)$removedAppId);
                    $user->syncPermissionsForApplication([], (int)$removedAppId);
                    DB::table('model_has_permission_groups')->where('model_id',$user->id)->where('model_type',User::class)->whereIn('permission_group_id',function($q)use($removedAppId){$q->select('id')->from('permission_groups')->where('application_id',$removedAppId);})->delete();
                    DB::table('application_user_module')->where('user_id',$user->id)->where('application_id',$removedAppId)->delete();
                }

                foreach ($data['applications'] as $appInput) {
                    $appId = $appInput['application_id'];
                    
                    if (isset($appInput['roles'])) {
                        $user->syncRolesForApplication($appInput['roles'], $appId);
                    }
                    if (isset($appInput['permissions'])) {
                        $user->syncPermissionsForApplication($appInput['permissions'], $appId);
                    }
                    if (isset($appInput['permission_groups'])) {
                        $groupIds = \App\Models\PermissionGroup::where('application_id', $appId)->whereIn('id', $appInput['permission_groups'])->pluck('id')->all();
                        $user->permissionGroups()->where('permission_groups.application_id', $appId)->detach();
                        if ($groupIds) {
                            $rows = array_map(fn($id) => ['permission_group_id'=>$id,'model_id'=>$user->id,'model_type'=>User::class], $groupIds);
                            DB::table('model_has_permission_groups')->insert($rows);
                        }
                    }
                    if (isset($appInput['module_ids'])) {
                        $moduleIds=\App\Models\Module::where('application_id',$appId)->whereIn('id',$appInput['module_ids'])->pluck('id')->all();
                        DB::table('application_user_module')->where('user_id',$user->id)->where('application_id',$appId)->delete();
                        if($moduleIds){$rows=array_map(fn($id)=>['application_id'=>$appId,'user_id'=>$user->id,'module_id'=>$id],$moduleIds);DB::table('application_user_module')->insert($rows);}
                    }
                }
            }

            \Illuminate\Support\Facades\DB::commit();

            // Sync this user only, asynchronously, after the transaction commits.
            if (isset($data['applications'])) {
                foreach ($data['applications'] as $appInput) {
                    SyncUserToProjectJob::dispatch($user->id, (int) $appInput['application_id'])->afterCommit();
                }
            }

            // Send Email
            try {
                // Load relationships for enriched email data
                $user->load(['employee.branch', 'employee.zonal', 'employee.region', 'employee.province', 'employee.reportingManager.user', 'employee.designation']);

                // Prepare email data
                $emailData = [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'email' => $user->email,
                        'user_type' => $user->user_type,
                        'email_verified_at' => $user->email_verified_at,
                    ],
                    'password' => ($user->user_type === 'staff') ? $data['id_number'] : $request->password,
                    'role' => $data['role'] ?? null,
                    'created_by' => $currentUser ? $currentUser->name : 'System',
                    'login_url' => trim(config('app.frontend_url') ?? config('app.url')),
                ];

                // Only add relationships if they exist
                if ($user->parent) {
                    $emailData['parent_name'] = $user->parent->name;
                }

                if ($user->employee && $user->employee->designation) {
                    $emailData['designation_name'] = $user->employee->designation->name;
                }

                if ($user->branch) {
                    $emailData['branch_name'] = $user->branch->name;
                }

                if ($user->zone) {
                    $emailData['zone_name'] = $user->zone->name;
                }

                if ($user->region) {
                    $emailData['region_name'] = $user->region->name;
                }

                if ($user->province) {
                    $emailData['province_name'] = $user->province->name;
                }

                Mail::to($user->email)->send(new UserCreateMail($emailData));

                $this->logActivity('EMAIL_SENT', 'User', "User login credentials email sent successfully to: {$user->email}", ['user_id' => $user->id]);
            } catch (\Throwable $th) {
                $this->logActivity('EMAIL_FAILED', 'User', "Failed to send user creation email: {$th->getMessage()}", null, 'error');
            }

            $user->load([
                'employee.branch',
                'employee.zonal',
                'employee.region',
                'employee.province',
                'employee.reportingManager.user',
                'employee.designation',
                'roles',
                'permissions',
                'applications'
            ]);

            $this->logActivity('CREATE', 'User', "Created user: {$user->username}", $data);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User created successfully',
                'data' => $userData
            ], 201);
        } catch (\Throwable $th) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create user',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function show(string $id)
    {
        try {
            $user = User::with(['employee.branch', 'employee.zonal', 'employee.region', 'employee.province', 'employee.reportingManager.user', 'employee.subordinates.user', 'employee.designation', 'roles', 'permissions', 'applications'])->find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User retrieved successfully',
                'data' => $userData
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve user',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function update(UpdateUserRequest $request, string $id)
    {
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $currentUser = auth("api")->user();
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $data = $request->validated();

            // Restrict admin user update to Super Admins only (both updating an existing admin or changing user_type to admin)
            $isTargetingAdmin = ($user->user_type === 'admin') || (isset($data['user_type']) && $data['user_type'] === 'admin');
            if ($isTargetingAdmin) {
                if (!$currentUser || !$currentUser->hasRole('Super Admin')) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Only Super Admin can manage admin users'
                    ], 403);
                }
            }

            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }

            // Handle user_type logic and employee updates
            $targetUserType = $data['user_type'] ?? $user->user_type;

            if ($targetUserType === 'staff') {
                $employeeData = array_intersect_key($data, array_flip([
                    'employee_code',
                    'employee_type',
                    'date_of_birth',
                    'id_type',
                    'id_number',
                    'phone',
                    'phone_primary',
                    'phone_secondary',
                    'address_line_1',
                    'city',
                    'state',
                    'country',
                    'postal_code',
                    'branch_id',
                    'zonal_id',
                    'region_id',
                    'province_id',
                    'designation_id',
                    'reporting_manager_id'
                ]));

                if ($user->employee) {
                    // Update existing employee
                    $user->employee->update($employeeData);
                } else {
                    // Create new employee if they were previously an admin
                    $employee = \App\Models\Employee::create($employeeData);
                    $data['employee_id'] = $employee->id;
                }

                // If id_number is updated, sync username
                if (isset($data['id_number'])) {
                    $data['username'] = $data['id_number'];
                }
            } else {
                // If changing from staff to admin, detach and delete the old employee record
                if ($user->employee) {
                    $employee = $user->employee;
                    $user->update(['employee_id' => null]);
                    $employee->delete();
                }
                $data['employee_id'] = null;
            }

            $user->update($data);

            if (isset($data['role'])) {
                $user->syncRoles([$data['role']]);
            }

            // Update applications and application-wise roles/permissions
            if (isset($data['applications'])) {
                $appUserMappings = [];
                foreach ($data['applications'] as $appInput) {
                    $appUserMappings[] = $appInput['application_id'];
                }
                $user->applications()->sync($appUserMappings);

                foreach ($data['applications'] as $appInput) {
                    $appId = $appInput['application_id'];
                    
                    if (isset($appInput['roles'])) {
                        $user->syncRolesForApplication($appInput['roles'], $appId);
                    } else {
                        $user->syncRolesForApplication([], $appId);
                    }
                    
                    if (isset($appInput['permissions'])) {
                        $user->syncPermissionsForApplication($appInput['permissions'], $appId);
                    } else {
                        $user->syncPermissionsForApplication([], $appId);
                    }
                    if (isset($appInput['permission_groups'])) {
                        $groupIds=\App\Models\PermissionGroup::where('application_id',$appId)->whereIn('id',$appInput['permission_groups'])->pluck('id')->all();
                        DB::table('model_has_permission_groups')->where('model_id',$user->id)->where('model_type',User::class)->whereIn('permission_group_id',function($q)use($appId){$q->select('id')->from('permission_groups')->where('application_id',$appId);})->delete();
                        if($groupIds){$rows=array_map(fn($id)=>['permission_group_id'=>$id,'model_id'=>$user->id,'model_type'=>User::class],$groupIds);DB::table('model_has_permission_groups')->insert($rows);}
                    }
                    if (isset($appInput['module_ids'])) {
                        $moduleIds=\App\Models\Module::where('application_id',$appId)->whereIn('id',$appInput['module_ids'])->pluck('id')->all();
                        DB::table('application_user_module')->where('user_id',$user->id)->where('application_id',$appId)->delete();
                        if($moduleIds){$rows=array_map(fn($id)=>['application_id'=>$appId,'user_id'=>$user->id,'module_id'=>$id],$moduleIds);DB::table('application_user_module')->insert($rows);}
                    }
                }
            }

            \Illuminate\Support\Facades\DB::commit();

            // Sync this user only, asynchronously, after the transaction commits.
            if (isset($data['applications'])) {
                foreach ($data['applications'] as $appInput) {
                    SyncUserToProjectJob::dispatch($user->id, (int) $appInput['application_id'])->afterCommit();
                }
            }

            $user->refresh();
            $user->load([
                'employee.branch',
                'employee.zonal',
                'employee.region',
                'employee.province',
                'employee.reportingManager.user',
                'employee.designation',
                'roles',
                'permissions',
                'applications'
            ]);

            $this->logActivity('UPDATE', 'User', "Updated user: {$user->username}", $data);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'User updated successfully',
                'data' => $userData
            ], 200);
        } catch (\Throwable $th) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update user',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Check if user is Super Admin
            if (!Auth::user()->hasRole('Super Admin')) {
                $this->logActivity('UNAUTHORIZED_DELETE', 'User', "Unauthorized user deletion attempt on ID: {$id}", ['target_user_id' => $id], 'warning');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only Super Admin can delete users'
                ], 403);
            }

            // Prevent self-deletion
            if ($user->id === Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You cannot delete your own account'
                ], 422);
            }

            $username = $user->username;
            $employee = $user->employee;

            $user->delete();

            // Cascade delete employee record
            if ($employee) {
                $employee->delete();
            }

            $this->logActivity('DELETE', 'User', "Deleted user: {$username}");

            return response()->json([
                'status' => 'success',
                'message' => 'User deleted successfully'
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete user',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function toggleStatus(string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Prevent self-deactivation
            if ($user->id === Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You cannot deactivate your own account'
                ], 422);
            }

            $user->is_active = !$user->is_active;
            $user->save();

            $this->logActivity('TOGGLE_STATUS', 'User', "Toggled user status: {$user->username} (" . ($user->is_active ? 'Active' : 'Inactive') . ")");

            return response()->json([
                'status' => 'success',
                'message' => 'User status updated successfully',
                'data' => [
                    'id' => $user->id,
                    'is_active' => $user->is_active
                ]
            ], 200);
        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to toggle user status',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function getFieldAccess(Request $request, string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $appId = $request->query('application_id');
            if (!$appId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'application_id query parameter is required'
                ], 400);
            }

            $rule = \App\Models\UserFieldAccessRule::where('user_id', $id)
                ->where('application_id', $appId)
                ->first();

            return response()->json([
                'status' => 'success',
                'message' => 'Field access retrieved successfully',
                'data' => [
                    'allowed_fields' => $rule ? $rule->allowed_fields : []
                ]
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve field access',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function updateFieldAccess(Request $request, string $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $request->validate([
                'application_id' => 'required|exists:applications,id',
                'allowed_fields' => 'required|array',
                'allowed_fields.*' => 'string'
            ]);

            $appId = $request->input('application_id');
            $allowedFields = $request->input('allowed_fields');

            // Enforce authorization for the GIAM admin to modify this application_id
            $currentUser = auth("api")->user();
            if (!$currentUser->hasRole('Super Admin') && !$currentUser->can('Project Access Assign')) {
                $hasAccess = $currentUser->applications()->where('applications.id', $appId)->exists();
                if (!$hasAccess) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'You are not authorized to assign access for this application.'
                    ], 403);
                }
            }

            $rule = \App\Models\UserFieldAccessRule::updateOrCreate(
                ['user_id' => $id, 'application_id' => $appId],
                ['allowed_fields' => $allowedFields]
            );

            $this->logActivity('UPDATE_FIELD_ACCESS', 'User', "Updated field access for user: {$user->username} on application {$appId}");

            return response()->json([
                'status' => 'success',
                'message' => 'Field access updated successfully',
                'data' => $rule
            ], 200);

        } catch (\Throwable $th) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update field access',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    public function projectAccess(Request $request, string $id, string $appId)
    {
        $user=User::findOrFail($id); $appId=(int)$appId; $current=auth('api')->user();
        if(!$current->hasRole('Super Admin') && !$current->applications()->whereKey($appId)->exists()) return response()->json(['status'=>'error','message'=>'Project access denied'],403);
        return response()->json(['status'=>'success','message'=>'Project access retrieved','data'=>[
            'application_id'=>$appId,
            'roles'=>$user->roles()->where('roles.application_id',$appId)->pluck('roles.id')->values(),
            'permissions'=>$user->permissions()->where('permissions.application_id',$appId)->pluck('permissions.id')->values(),
            'permission_groups'=>DB::table('model_has_permission_groups as m')->join('permission_groups as g','g.id','=','m.permission_group_id')->where('m.model_id',$user->id)->where('m.model_type',User::class)->where('g.application_id',$appId)->pluck('g.id')->values(),
            'module_ids'=>DB::table('application_user_module')->where('user_id',$user->id)->where('application_id',$appId)->pluck('module_id')->values(),
        ]]);
    }

    public function effectiveAccess(Request $request, string $id)
    {
        $appId=(int)$request->query('application_id');
        $user=User::findOrFail($id);
        $current=auth('api')->user();
        if(!$current->hasRole('Super Admin') && !$current->applications()->whereKey($appId)->exists()) return response()->json(['status'=>'error','message'=>'Project access denied'],403);
        $roles=$user->roles()->where('roles.application_id',$appId)->with('permissions')->get();
        $groups=$user->permissionGroups()->where('permission_groups.application_id',$appId)->with('permissions')->get();
        $direct=$user->permissions()->where('permissions.application_id',$appId)->get();
        $map=[];
        foreach($roles as $r)foreach($r->permissions as $p)$map[$p->id]??=['id'=>$p->id,'name'=>$p->name,'sources'=>[]];
        foreach($roles as $r)foreach($r->permissions as $p)$map[$p->id]['sources'][]=['type'=>'role','name'=>$r->name];
        foreach($groups as $g)foreach($g->permissions as $p){$map[$p->id]??=['id'=>$p->id,'name'=>$p->name,'sources'=>[]];$map[$p->id]['sources'][]=['type'=>'group','name'=>$g->name];}
        foreach($direct as $p){$map[$p->id]??=['id'=>$p->id,'name'=>$p->name,'sources'=>[]];$map[$p->id]['sources'][]=['type'=>'direct','name'=>null];}
        return response()->json(['status'=>'success','message'=>'Effective access retrieved','data'=>array_values($map)]);
    }

    public function updateApplicationAccess(Request $request, string $id, string $appId)
    {
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $request->validate([
                'roles' => 'array',
                'roles.*' => 'exists:roles,id',
                'permissions' => 'array',
                'permissions.*' => 'exists:permissions,id',
            ]);

            // Enforce authorization for the GIAM admin to modify this application_id
            $currentUser = auth("api")->user();
            if (!$currentUser->hasRole('Super Admin') && !$currentUser->can('Project Access Assign')) {
                $hasAccess = $currentUser->applications()->where('applications.id', $appId)->exists();
                if (!$hasAccess) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'You are not authorized to assign access for this application.'
                    ], 403);
                }
            }

            // Sync application without detaching others
            $user->applications()->syncWithoutDetaching([$appId]);

            // Sync roles and permissions for this specific application
            $roleIds = $request->input('roles', []);
            $user->syncRolesForApplication($roleIds, $appId);

            $permissionIds = $request->input('permissions', []);
            $user->syncPermissionsForApplication($permissionIds, $appId);
            if ($request->has('permission_groups')) {
                $groupIds=\App\Models\PermissionGroup::where('application_id',$appId)->whereIn('id',$request->input('permission_groups',[]))->pluck('id')->all();
                DB::table('model_has_permission_groups')->where('model_id',$user->id)->where('model_type',User::class)->whereIn('permission_group_id',function($q)use($appId){$q->select('id')->from('permission_groups')->where('application_id',$appId);})->delete();
                if($groupIds){$rows=array_map(fn($gid)=>['permission_group_id'=>$gid,'model_id'=>$user->id,'model_type'=>User::class],$groupIds);DB::table('model_has_permission_groups')->insert($rows);}
            }
            if ($request->has('module_ids')) {
                $moduleIds=\App\Models\Module::where('application_id',$appId)->whereIn('id',$request->input('module_ids',[]))->pluck('id')->all();
                DB::table('application_user_module')->where('user_id',$user->id)->where('application_id',$appId)->delete();
                if($moduleIds){$rows=array_map(fn($mid)=>['application_id'=>$appId,'user_id'=>$user->id,'module_id'=>$mid],$moduleIds);DB::table('application_user_module')->insert($rows);}
            }

            \Illuminate\Support\Facades\DB::commit();

            if ($app = Application::find($appId)) { SyncUserToProjectJob::dispatch($user->id, (int)$app->id)->afterCommit(); }

            $user->refresh();
            $user->load([
                'employee.branch',
                'employee.zonal',
                'employee.region',
                'employee.province',
                'employee.reportingManager.user',
                'employee.designation',
                'roles',
                'permissions',
                'applications'
            ]);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            $this->logActivity('UPDATE_APPLICATION_ACCESS', 'User', "Updated application access for user: {$user->username} on application {$appId}");

            return response()->json([
                'status' => 'success',
                'message' => 'Application access updated successfully',
                'data' => $userData
            ], 200);

        } catch (\Throwable $th) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update application access',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function removeApplicationAccess(Request $request, string $id, string $appId)
    {
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            // Enforce authorization
            $currentUser = auth("api")->user();
            if (!$currentUser->hasRole('Super Admin') && !$currentUser->can('Project Access Assign')) {
                $hasAccess = $currentUser->applications()->where('applications.id', $appId)->exists();
                if (!$hasAccess) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'You are not authorized to modify access for this application.'
                    ], 403);
                }
            }

            // Clear roles and permissions for this application
            $user->syncRolesForApplication([], $appId);
            $user->syncPermissionsForApplication([], $appId);

            // Detach application
            $user->applications()->detach($appId);
            DB::table('application_user_module')->where('user_id',$user->id)->where('application_id',$appId)->delete();

            \Illuminate\Support\Facades\DB::commit();

            if ($app = Application::find($appId)) { SyncUserToProjectJob::dispatch($user->id, (int)$app->id)->afterCommit(); }

            $user->refresh();
            $user->load([
                'employee.branch',
                'employee.zonal',
                'employee.region',
                'employee.province',
                'employee.reportingManager.user',
                'employee.designation',
                'roles',
                'permissions',
                'applications'
            ]);

            $userData = $user->toArray();
            if (isset($userData['roles'])) {
                foreach ($userData['roles'] as &$role) {
                    unset($role['pivot']);
                }
            }

            $this->logActivity('REMOVE_APPLICATION_ACCESS', 'User', "Removed application access for user: {$user->username} on application {$appId}");

            return response()->json([
                'status' => 'success',
                'message' => 'Application access removed successfully',
                'data' => $userData
            ], 200);

        } catch (\Throwable $th) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to remove application access',
                'error' => config('app.debug') ? $th->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
