<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Casts\Attribute; use Illuminate\Database\Eloquent\Model; use Illuminate\Support\Facades\Crypt;
class ProjectSyncCredential extends Model
{
 protected $fillable=['application_id','api_key','sync_endpoint_url','launch_url'];
 protected function apiKey():Attribute{return Attribute::make(get:function($v){if(!$v)return null;try{return Crypt::decryptString($v);}catch(\Throwable){return $v;}},set:fn($v)=>$v?Crypt::encryptString($v):null);}
 public function application(){return $this->belongsTo(Application::class);}
}
