<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PermissionGroup extends Model
{
    protected $fillable=['name','description','application_id','source','synced_at'];
    protected $casts=['synced_at'=>'datetime'];
    public function application(){return $this->belongsTo(Application::class);}
    public function permissions(){return $this->belongsToMany(Permission::class,'permission_group_permission');}
    public function roles(){return $this->belongsToMany(Role::class,'role_permission_group');}
    public function users(){return $this->morphedByMany(User::class,'model','model_has_permission_groups','permission_group_id','model_id');}
}
