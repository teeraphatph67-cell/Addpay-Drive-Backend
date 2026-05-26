<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FileChunks extends Model
{
    // ชื่อตาราง
    protected $table = 'file_chunks';

    // คีย์หลัก
    protected $primaryKey = 'id';

    // ปิด timestamps เพราะตารางยังไม่มี created_at/updated_at
    // (ถ้าคุณเพิ่มคอลัมน์สองตัวนี้ใน DB แล้ว ให้ลบบรรทัดนี้ทิ้ง)
    public $timestamps = false;

    // ฟิลด์ที่อนุญาตให้บันทึกแบบ mass assignment
    protected $fillable = [
        'upload_session_id',
        'chunk_number',
        'chunk_path',
        'created_at',
    ];
}
