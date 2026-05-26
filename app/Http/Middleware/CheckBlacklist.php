<?php

namespace App\Http\Middleware;

use Closure;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;

class CheckBlacklist
{
    public function handle($request, Closure $next)
    {
        try {
            $token = JWTAuth::parseToken()->getToken();
        } catch (\Exception $e) {
            return response()->json(['error' => 'Token ไม่ถูกต้อง'], 401);
        }

        // ตรวจสอบ token ใน blacklist
        $isBlacklisted = DB::table('jwt_blacklist')->where('token', $token)->exists();
        if ($isBlacklisted) {
            return response()->json(['error' => 'Token ถูกยกเลิก'], 401);
        }

        return $next($request);
    }
}
