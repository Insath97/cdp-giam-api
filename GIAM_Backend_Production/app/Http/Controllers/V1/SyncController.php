<?php
namespace App\Http\Controllers\V1;
use App\Http\Controllers\Controller; use App\Models\Permission; use App\Models\PermissionGroup; use App\Models\Role; use Illuminate\Http\Request; use Illuminate\Support\Facades\DB;
class SyncController extends Controller
{
 public function syncRolesAndPermissions(Request $request){$appId=(int)$request->sync_application_id;$data=$request->validate([
  'modules'=>'sometimes|array','modules.*.id'=>'nullable','modules.*.name'=>'required|string','modules.*.code'=>'required|string','modules.*.description'=>'nullable|string',
  'roles'=>'sometimes|array','roles.*.name'=>'required|string','roles.*.guard_name'=>'nullable|string','roles.*.permission_group_names'=>'sometimes|array','roles.*.permission_group_names.*'=>'string',
  'permission_groups'=>'sometimes|array','permission_groups.*.name'=>'required|string','permission_groups.*.description'=>'nullable|string','permission_groups.*.permission_names'=>'sometimes|array','permission_groups.*.permission_names.*'=>'string',
  'permissions'=>'sometimes|array','permissions.*.name'=>'required|string','permissions.*.guard_name'=>'nullable|string','permissions.*.group_name'=>'nullable|string','permissions.*.module_code'=>'nullable|string'
 ]);DB::beginTransaction();try{
  $moduleMap=[];foreach($data['modules']??[] as $m){$module=\App\Models\Module::updateOrCreate(['application_id'=>$appId,'code'=>$m['code']],['name'=>$m['name'],'description'=>$m['description']??null]);$moduleMap[$module->code]=$module->id;}
  $permMap=[];foreach($data['permissions']??[] as $p){$perm=Permission::updateOrCreate(['name'=>$p['name'],'guard_name'=>$p['guard_name']??'api','application_id'=>$appId],['group_name'=>$p['group_name']??'General','module_id'=>isset($p['module_code'])?($moduleMap[$p['module_code']]??null):null,'source'=>'synced','synced_at'=>now()]);$permMap[$perm->name]=$perm->id;}
  $groupMap=[];foreach($data['permission_groups']??[] as $g){$group=PermissionGroup::updateOrCreate(['name'=>$g['name'],'application_id'=>$appId],['description'=>$g['description']??null,'source'=>'synced','synced_at'=>now()]);$groupMap[$group->name]=$group; if(isset($g['permission_names']))$group->permissions()->sync(array_values(array_filter(array_map(fn($n)=>$permMap[$n]??null,$g['permission_names']))));}
  foreach($data['roles']??[] as $r){$role=Role::updateOrCreate(['name'=>$r['name'],'guard_name'=>$r['guard_name']??'api','application_id'=>$appId],['source'=>'synced','synced_at'=>now()]);if(isset($r['permission_group_names']))$role->permissionGroups()->sync(array_values(array_filter(array_map(fn($n)=>$groupMap[$n]->id??null,$r['permission_group_names']))));}
  DB::commit();return response()->json(['status'=>'success','message'=>'Project catalog synchronized','data'=>['modules_synced'=>count($moduleMap),'roles_synced'=>count($data['roles']??[]),'permission_groups_synced'=>count($groupMap),'permissions_synced'=>count($permMap)]]);
 }catch(\Throwable $e){DB::rollBack();return response()->json(['status'=>'error','message'=>'Catalog synchronization failed'],500);}}
}
