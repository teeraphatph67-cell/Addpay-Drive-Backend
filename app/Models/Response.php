<?php
namespace App\Models; // แนะนำใส่ namespace ถ้าใช้ใน Laravel/Lumen
use Illuminate\Database\Eloquent\Model;
class Response
{
    public function success($result = [], $message = '', $code = 200)
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $result,
        ]);
        exit;
    }

    public function error($message = '', $code = 400)
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $message,
            'statusCode' => $code,
        ]);
        exit;
    }
}
