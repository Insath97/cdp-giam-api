<?php
namespace App\Http\Controllers\V1;
use App\Http\Controllers\Controller; use App\Models\ActivityLog; use Illuminate\Http\Request; use Illuminate\Routing\Controllers\HasMiddleware; use Illuminate\Routing\Controllers\Middleware;
class ActivityLogController extends Controller implements HasMiddleware
{
 public static function middleware():array{return [new Middleware('permission:Activity Log Index',only:['index'])];}
 public function index(Request $request){$q=ActivityLog::with('user:id,name,email')->latest();if($request->filled('action'))$q->where('action',$request->action);if($request->filled('module'))$q->where('module',$request->module);if($request->filled('user_id'))$q->where('user_id',$request->integer('user_id'));if($request->filled('search')){$x=$request->string('search');$q->where(fn($w)=>$w->where('description','like',"%$x%")->orWhere('action','like',"%$x%"));}return response()->json(['status'=>'success','message'=>'Activity logs retrieved successfully','data'=>$q->paginate(min((int)$request->query('per_page',25),100))]);}
}
