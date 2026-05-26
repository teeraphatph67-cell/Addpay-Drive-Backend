<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CameraDetail extends Model
{
    // ชื่อตาราง
    protected $table = 'camera_details';

    // คีย์หลัก
    protected $primaryKey = 'id';

    // ปิด timestamps เพราะตารางยังไม่มี created_at/updated_at
    // (ถ้าคุณเพิ่มคอลัมน์สองตัวนี้ใน DB แล้ว ให้ลบบรรทัดนี้ทิ้ง)
    public $timestamps = true;

    // ฟิลด์ที่อนุญาตให้บันทึกแบบ mass assignment
    protected $fillable = [
        'ip_address',
        'animal_id',
        'camera_name',
        'model_camera',
        'rtsp_url',
        'angle_install',
        'date_install',
        'status',
        'ip_vpn',
    ];

    //    public function Details_Camera()
    // {
    //     return $this->hasMany(CameraDetail::class, 'animal_id', 'id');
    // }

 public function animal()
    {
        return $this->belongsTo(Animaldb::class, 'animal_id','id');
    }







}
