<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Files extends Model
{
    // ชื่อตาราง
    protected $table = 'files';

    // คีย์หลัก
    protected $primaryKey = 'id';

    // ปิด timestamps เพราะตารางยังไม่มี created_at/updated_at
    // (ถ้าคุณเพิ่มคอลัมน์สองตัวนี้ใน DB แล้ว ให้ลบบรรทัดนี้ทิ้ง)
    public $timestamps = true;

    // ฟิลด์ที่อนุญาตให้บันทึกแบบ mass assignment
    protected $fillable = [
        'folder_id',
        'drive_id',
        'owner_id',
        'created_by_role',
        'file_name',
        'file_path',
        'mime_type',
        'file_ext',
        'size_mb',
        'is_starred',
        'is_trashed',
        'modified_by',
        'last_opened_at',
        'last_opened_by'
    ];

    protected $casts = [
        'last_opened_at' => 'string',
        'is_starred'     => 'integer',
        'is_trashed'     => 'integer',
    ];



     public function FileOne()
    {
        return $this->belongsTo(Drive::class, 'drive_id');
    }


         public function OneFolder()
    {
        return $this->belongsTo(Folders::class, 'folder_id');
    }

        public function permissions()
{
    return $this->hasMany(Permissions::class, 'target_id')
                ->where('target_type', 'file');
}

 public function folder()
    {
        return $this->belongsTo(Folders::class, 'folder_id');
    }

    public function drive()
    {
        return $this->belongsTo(Drive::class, 'drive_id');
    }

    public function openedByUser()
{
    return $this->belongsTo(User::class, 'owner_id');
}

public function parentFolder()
{
    return $this->belongsTo(Folders::class, 'folder_id');
}
}
