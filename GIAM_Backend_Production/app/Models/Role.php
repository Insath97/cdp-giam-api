<?php
namespace App\Models;
use Spatie\Permission\Models\Role as SpatieRole;
class Role extends SpatieRole
{
    protected $fillable=['name','guard_name','application_id','is_protected','source','synced_at'];
    protected $casts=['synced_at'=>'datetime','is_protected'=>'boolean'];
    public function application(){return $this->belongsTo(Application::class);}
    public function permissionGroups(){return $this->belongsToMany(PermissionGroup::class,'role_permission_group');}
}
