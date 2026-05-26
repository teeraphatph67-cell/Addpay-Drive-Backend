<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Drive extends Model
{
    // ชื่อตาราง
    protected $table = 'drive';

    // คีย์หลัก
    protected $primaryKey = 'id';

    // ปิด timestamps เพราะตารางยังไม่มี created_at/updated_at
    // (ถ้าคุณเพิ่มคอลัมน์สองตัวนี้ใน DB แล้ว ให้ลบบรรทัดนี้ทิ้ง)
    public $timestamps = true;

    // ฟิลด์ที่อนุญาตให้บันทึกแบบ mass assignment
    protected $fillable = [
        'user_id',
        'name',
        'created_at'
    ];

     public function trashed_folders()
    {
        return $this->hasMany(Folders::class, 'drive_id')
                    ->where('is_trashed', 1)
                    ->where(function ($q) {
                        $q->whereNull('parent_id') 
                          ->orWhereHas('parent', function ($q2) {
                              $q2->where('is_trashed', 0);
                          });
                    });
    }

    public function trashed_files()
{
    return $this->hasMany(Files::class, 'drive_id')
                ->where('is_trashed', 1)
                ->where(function ($q) {
                    $q->whereNull('folder_id')
                      ->orWhereHas('folder', function ($q2) {
                          $q2->where('is_trashed', 0);
                      });
                });
}

    public function trashedFolders()
    {
        return $this->hasMany(Folders::class, 'drive_id')
                    ->where('is_trashed', 1);
    }

    public function trashedFiles()
    {
        return $this->hasMany(Files::class, 'drive_id')
                    ->where('is_trashed', 1);
    }

     public function foldersActive()
    {
        return $this->hasMany(Folders::class, 'drive_id')
                    ->whereNull('parent_id')
                    ->where('is_trashed', 0);
    }

    // root folders ที่อยู่ในถังขยะ
    public function foldersTrashed()
    {
        return $this->hasMany(Folders::class, 'drive_id')
                    ->whereNull('parent_id')
                    ->where('is_trashed', 1);
    }

    // ไฟล์ root ที่ยังไม่ลบ (ถ้ามีเคสนี้)
    public function filesActive()
    {
        return $this->hasMany(Files::class, 'drive_id')
                    ->whereNull('folder_id')
                    ->where('is_trashed', 0);
    }

    // ไฟล์ root ที่อยู่ในถังขยะ
    public function filesTrashed()
    {
        return $this->hasMany(Files::class, 'drive_id')
                    ->whereNull('folder_id')
                    ->where('is_trashed', 1);
    }


 public function DriveOne()
    {
        return $this->belongsTo(User::class, 'user_id','id');
    }


    public function user()
{
    return $this->belongsTo(\App\Models\User::class, 'user_id');
}

public function parent()
{
    return $this->belongsTo(Folders::class, 'parent_id');
}


public function children()
{
    return $this->hasMany(Folders::class, 'parent_id');
}

 public function folders()
    {
        return $this->hasMany(Folders::class, 'drive_id')
         ->whereNull('parent_id');
    }


    public function starred_folders()
    {
        return $this->hasMany(Folders::class, 'drive_id')
                    ->where('is_starred', 1)
                    ->where('is_trashed', 0)
                    ->where(function ($q) {
                        $q->whereNull('parent_id')           // เป็น root folder
                          ->orWhereHas('parent', function ($q2) {
                              $q2->where('is_starred', 0);   // parent ยังไม่ติดดาว → ตัวนี้คือ root ของ star
                          });
                    });
    }

    // ไฟล์ที่ติดดาว (ทุกอันใน drive ที่ไม่อยู่ถังขยะ)
public function starred_files()
{
    return $this->hasMany(Files::class, 'drive_id')
        ->where('is_starred', 1)
        ->where('is_trashed', 0);
}

    // Drive.php
public function starredFolders()
{
    return $this->hasMany(Folders::class, 'drive_id')
                ->where('is_starred', 1)
                ->where('is_trashed', 0);
}


// 
}
    





