<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Traits\ActivityLogTrait;
use App\Models\SsoHandoffToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use ActivityLogTrait;
    private const COOKIE = 'auth_token';

    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['required','string','max:255'],
            'password' => ['required','string','max:255'],
        ]);

        $user = User::query()
            ->where(function ($q) use ($data) {
                $q->where('username', $data['login'])->orWhere('email', $data['login']);
            })->first();

        if (!$user && ($employee = \App\Models\Employee::where('id_number', $data['login'])->first())) {
            $user = User::where('employee_id', $employee->id)->first();
        }

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['status'=>'error','message'=>'Invalid credentials'], 401);
        }
        if (!$user->canLogin()) {
            return response()->json(['status'=>'error','message'=>'Account is deactivated'], 403);
        }

        $token = Auth::guard('api')->login($user);
        $user->updateLastLogin($request->ip());
        $this->logActivity('LOGIN', 'Authentication', 'User logged in successfully');

        $cookie = cookie(
            self::COOKIE,
            $token,
            (int) config('jwt.ttl'),
            '/',
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            config('session.same_site', 'lax')
        );

        return response()->json([
            'status'=>'success',
            'message'=>'Login successful',
            'data'=>['user'=>$this->userPayload($user), 'expires_in'=>config('jwt.ttl')*60]
        ])->withCookie($cookie);
    }

    public function refresh()
    {
        try {
            $token = Auth::guard('api')->refresh();
            $user = Auth::guard('api')->user();
            if (!$user || !$user->canLogin()) {
                throw new \RuntimeException('Account is not active');
            }
            $cookie = cookie(self::COOKIE, $token, (int) config('jwt.ttl'), '/', config('session.domain'), (bool) config('session.secure'), true, false, config('session.same_site', 'lax'));
            return response()->json(['status'=>'success','message'=>'Session refreshed','data'=>['user'=>$this->userPayload($user),'expires_in'=>config('jwt.ttl')*60]])->withCookie($cookie);
        } catch (\Throwable $e) {
            return response()->json(['status'=>'error','message'=>'Session refresh failed'], 401)->withCookie(Cookie::forget(self::COOKIE));
        }
    }

    public function logout()
    {
        try { $u=Auth::guard('api')->user(); if($u) $this->logActivity('LOGOUT','Authentication','User logged out'); Auth::guard('api')->logout(); } catch (\Throwable) {}
        return response()->json(['status'=>'success','message'=>'Logout successful'])->withCookie(Cookie::forget(self::COOKIE));
    }

    public function me()
    {
        return response()->json(['status'=>'success','message'=>'User details fetched successfully','data'=>['user'=>$this->userPayload(Auth::guard('api')->user())]]);
    }

    public function generateSsoToken(Request $request)
    {
        $data = $request->validate(['application_id'=>['required','integer','exists:applications,id']]);
        $user = Auth::guard('api')->user();
        $appId = (int) $data['application_id'];
        if (!$user->hasRole('Super Admin') && !$user->applications()->whereKey($appId)->exists()) {
            return response()->json(['status'=>'error','message'=>'You do not have access to this project.'],403);
        }
        $plain = Str::random(64);
        $handoff = SsoHandoffToken::create(['user_id'=>$user->id,'application_id'=>$appId,'token'=>hash('sha256',$plain),'expires_at'=>now()->addSeconds(60)]);
        return response()->json(['status'=>'success','message'=>'SSO handoff created','data'=>['token'=>$plain,'expires_at'=>$handoff->expires_at]]);
    }

    public function exchangeSsoToken(Request $request)
    {
        $request->validate(['token'=>['required','string','max:128']]);
        $appId = (int) $request->sync_application_id;
        $handoff = SsoHandoffToken::where('token',hash('sha256',$request->token))->where('application_id',$appId)->where('consumed',false)->where('expires_at','>',now())->first();
        if (!$handoff) return response()->json(['status'=>'error','message'=>'Invalid, expired or consumed SSO token.'],401);
        $handoff->update(['consumed'=>true]);
        $user = User::with(['employee.branch','employee.designation'])->findOrFail($handoff->user_id);
        $roles = $user->roles()->where('roles.application_id',$appId)->with('permissions')->get()->map(fn($r)=>['id'=>$r->id,'name'=>$r->name,'permissions'=>$r->permissions->map(fn($p)=>['id'=>$p->id,'name'=>$p->name])]);
        $permissions = $user->permissions()->where('permissions.application_id',$appId)->get(['permissions.id','permissions.name'])->map(fn($p)=>['id'=>$p->id,'name'=>$p->name]);
        return response()->json(['status'=>'success','message'=>'SSO exchange successful','data'=>['user'=>['id'=>$user->id,'name'=>$user->name,'username'=>$user->username,'email'=>$user->email,'user_type'=>$user->user_type,'is_active'=>$user->is_active,'roles'=>$roles,'permissions'=>$permissions]]]);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing(['roles'=>fn($q)=>$q->with('permissions'),'applications:id,name,code,description,app_url,is_active']);
        return [
            'id'=>$user->id,'name'=>$user->name,'username'=>$user->username,'email'=>$user->email,
            'user_type'=>$user->user_type,'is_active'=>$user->is_active,'can_login'=>$user->can_login,
            'last_login_at'=>$user->last_login_at,'applications'=>$user->applications,
            'roles'=>$user->roles->map(fn($r)=>['id'=>$r->id,'name'=>$r->name,'application_id'=>$r->application_id,'permissions'=>$r->permissions->map(fn($p)=>['id'=>$p->id,'name'=>$p->name])->values()])->values(),
            'has_giam_internal_access'=>$user->hasRole('Super Admin') || $user->roles->contains(fn($r)=>is_null($r->application_id)),
        ];
    }
}
