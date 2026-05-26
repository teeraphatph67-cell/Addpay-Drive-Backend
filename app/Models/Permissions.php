<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class Permissions extends Model
{
    // ชื่อตาราง
    protected $table = 'permissions';

    // คีย์หลัก
    protected $primaryKey = 'id';

    // ปิด timestamps เพราะตารางยังไม่มี created_at/updated_at
    // (ถ้าคุณเพิ่มคอลัมน์สองตัวนี้ใน DB แล้ว ให้ลบบรรทัดนี้ทิ้ง)
    public $timestamps = false;

    // ฟิลด์ที่อนุญาตให้บันทึกแบบ mass assignment
    protected $fillable = [
        'target_type',
        'target_id',
        'owner_id',
        'shared_with_user',
        'shared_email',
        'shared_link_token',
        'scope',
        'allow_view',
        'allow_edit',
        'allow_download',
        'created_at',
    ];

        protected $casts = [
        'allow_view'     => 'boolean',
        'allow_edit'     => 'boolean',
        'allow_download' => 'boolean',
    ];

public function sharedUser()
{
    return $this->belongsTo(User::class, 'shared_with_user');
}





}
