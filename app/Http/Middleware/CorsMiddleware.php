<?php
namespace App\Http\Middleware;

use Closure;

class CorsMiddleware
{
    public function handle($request, Closure $next)
    {
        $headers = [
            'Access-Control-Allow-Origin'      => '*', // เปิดทุก origin
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-Requested-With, Accept, Origin',
            'Access-Control-Expose-Headers'    => 'Content-Disposition',
        ];

        // ถ้าเป็น preflight (OPTIONS) ให้ตอบกลับเลย
        if ($request->isMethod('OPTIONS')) {
            return response('OK', 200)->withHeaders($headers);
        }

        // ปล่อยให้ request วิ่งต่อไป
        $response = $next($request);

        // ใส่ headers เพิ่มใน response
        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}
