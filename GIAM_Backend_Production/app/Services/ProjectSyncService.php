<?php
namespace App\Services;
use App\Models\Application;
use App\Models\PermissionGroup;
use App\Models\ProjectSyncCredential;
use App\Models\User;
use App\Models\UserFieldAccessRule;
use App\Models\UserProjectSyncLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class ProjectSyncService
{
    public function syncUserToProject(User $user, Application $application): bool
    {
        $user->loadMissing(['employee.branch','employee.designation','roles.permissions','permissionGroups.permissions']);
        if (!$user->applications()->whereKey($application->id)->exists()) return false;
        $credentials=ProjectSyncCredential::where('application_id',$application->id)->first();
        if(!$credentials || !$credentials->sync_endpoint_url) return false;
        $rule=UserFieldAccessRule::where('user_id',$user->id)->where('application_id',$application->id)->first();
        $full=[
            'id'=>$user->id,'name'=>$user->name,'username'=>$user->username,'email'=>$user->email,'user_type'=>$user->user_type,'is_active'=>$user->is_active,
            'employee'=>$user->employee?[ 'employee_code'=>$user->employee->employee_code,'id_number'=>$user->employee->id_number,'phone'=>$user->employee->phone,'branch_id'=>$user->employee->branch_id,'designation_id'=>$user->employee->designation_id ]:null,
            'roles'=>$user->roles->where('application_id',$application->id)->map(fn($r)=>['id'=>$r->id,'name'=>$r->name])->values()->all(),
            'permission_groups'=>$user->permissionGroups->where('application_id',$application->id)->map(fn($g)=>['id'=>$g->id,'name'=>$g->name])->values()->all(),
            'permissions'=>$user->permissions->where('application_id',$application->id)->map(fn($p)=>['id'=>$p->id,'name'=>$p->name])->values()->all(),
            'modules'=>$user->projectModules($application->id)->get(['modules.id','modules.name','modules.code'])->map(fn($m)=>['id'=>$m->id,'name'=>$m->name,'code'=>$m->code])->values()->all(),
        ];
        $payload=$rule && is_array($rule->allowed_fields) && count($rule->allowed_fields)
            ? $this->filter($full,$rule->allowed_fields)
            : $full;
        $log=UserProjectSyncLog::create(['application_id'=>$application->id,'user_id'=>$user->id,'status'=>'pending','type'=>'user_push','payload'=>['user_id'=>$user->id,'fields'=>array_keys($payload)]]);
        try{
            $apiKey=Crypt::decryptString($credentials->api_key);
        }catch(\Throwable){$apiKey=$credentials->api_key;}
        try{
            $response=Http::timeout((int)env('PROJECT_SYNC_TIMEOUT',15))->retry(2,250)->withHeaders(['X-App-Id'=>(string)$application->id,'X-Api-Key'=>$apiKey,'Accept'=>'application/json'])->post($credentials->sync_endpoint_url,['user'=>$payload,'project'=>['id'=>$application->id,'code'=>$application->code]]);
            $log->update(['status'=>$response->successful()?'success':'failed','response'=>mb_substr($response->body(),0,10000),'error_message'=>$response->successful()?null:'Project returned HTTP '.$response->status()]);
            return $response->successful();
        }catch(\Throwable $e){$log->update(['status'=>'failed','error_message'=>mb_substr($e->getMessage(),0,4000)]); Log::error('Project user sync failed',['user_id'=>$user->id,'application_id'=>$application->id,'error'=>$e->getMessage()]); return false;}
    }
    public function syncUsersToProject(Application $application): bool
    {
        $ok=true;
        User::whereHas('applications',fn($q)=>$q->whereKey($application->id))->chunkById(100,function($users)use($application,&$ok){foreach($users as $user){$ok=$this->syncUserToProject($user,$application)&&$ok;}});
        return $ok;
    }
    private function filter(array $source,array $fields): array
    {
        $out=['id'=>$source['id']];
        foreach($fields as $field){ if($field==='id') continue; $parts=explode('.',$field); $value=$source; foreach($parts as $part){if(!is_array($value)||!array_key_exists($part,$value)){continue 2;} $value=$value[$part];} $ref=&$out; foreach($parts as $part){$ref=&$ref[$part];} $ref=$value; }
        return $out;
    }
}
