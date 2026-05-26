<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Activities extends Model
{
    // ชื่อตาราง
    protected $table = 'activities';

    // คีย์หลัก
    protected $primaryKey = 'id';

    // ปิด timestamps เพราะตารางยังไม่มี created_at/updated_at
    // (ถ้าคุณเพิ่มคอลัมน์สองตัวนี้ใน DB แล้ว ให้ลบบรรทัดนี้ทิ้ง)
    public $timestamps = false;

    // ฟิลด์ที่อนุญาตให้บันทึกแบบ mass assignment
    protected $fillable = [
        'user_id',
        'action',
        'target_type',
        'target_id',
        'ip_address',
        'created_at',
    ];
}
