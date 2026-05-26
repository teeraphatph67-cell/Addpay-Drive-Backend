<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UploadSessions extends Model
{
    // ชื่อตาราง
    protected $table = 'upload_sessions';

    // คีย์หลัก
    protected $primaryKey = 'id';

    // ปิด timestamps เพราะตารางยังไม่มี created_at/updated_at
    // (ถ้าคุณเพิ่มคอลัมน์สองตัวนี้ใน DB แล้ว ให้ลบบรรทัดนี้ทิ้ง)
    public $timestamps = true;

    // ฟิลด์ที่อนุญาตให้บันทึกแบบ mass assignment
    protected $fillable = [
        'user_id',
        'drive_id',
        'folder_id',
        'created_by_role',
        'original_name',
        'temp_path',
        'total_size',
        'uploaded_size',
        'status',
        'created_at',
    ];







}
