<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\File; 

class Folders extends Model
{
    // ชื่อตาราง
    protected $table = 'folders';

    // คีย์หลัก
    protected $primaryKey = 'id';

    // ปิด timestamps เพราะตารางยังไม่มี created_at/updated_at
    // (ถ้าคุณเพิ่มคอลัมน์สองตัวนี้ใน DB แล้ว ให้ลบบรรทัดนี้ทิ้ง)
    public $timestamps = true;

    // ฟิลด์ที่อนุญาตให้บันทึกแบบ mass assignment
    protected $fillable = [
        'drive_id',
        'parent_id',
        'created_by_role',
        'name',
        'owner_id',
        'url_file',
        'is_starred',
        'is_trashed',
        'created_at',
        'updated_at',
    ];

           public function Folder_drive()
    {
        return $this->hasMany(Drive::class, 'drive_id', 'id');
    }


     public function DriveOne()
    {
        return $this->belongsTo(Drive::class, 'drive_id');
    }

           public function ManyFile()
    {
        return $this->hasMany(Files::class, 'folder_id');
    }
    
 public function parent()
    {
        return $this->belongsTo(Folders::class, 'parent_id');
    }

    // โฟลเดอร์ลูก (โฟลเดอร์ที่อยู่ใต้เรา)
    public function children()
    {
        return $this->hasMany(Folders::class, 'parent_id');
    }

        public function files()
    {
        return $this->hasMany(Files::class, 'folder_id');
        // ถ้า model ชื่อ File เดี่ยวให้เปลี่ยนเป็น File::class
    }
    // ถ้าอยากให้ children ซ้อน children อีกเรื่อย ๆ
    public function childrenRecursive()
    {
        return $this->children()->with(['childrenRecursive', 'files']);
    }


    

    public function permissions()
{
    return $this->hasMany(Permissions::class, 'target_id')
                ->where('target_type', 'files');
}

 public function childrenActive()
    {
        return $this->hasMany(Folders::class, 'parent_id')
                    ->where('is_trashed', 0);
    }

    // โฟลเดอร์ลูกที่อยู่ในถังขยะ
    public function childrenTrashed()
    {
        return $this->hasMany(Folders::class, 'parent_id')
                    ->where('is_trashed', 1);
    }

    // ไฟล์ปกติในโฟลเดอร์นี้
    public function filesActive()
    {
        return $this->hasMany(Files::class, 'folder_id')
                    ->where('is_trashed', 0);
    }

    // ไฟล์ที่อยู่ในถังขยะในโฟลเดอร์นี้
    public function filesTrashed()
    {
        return $this->hasMany(Files::class, 'folder_id')
                    ->where('is_trashed', 1);
    }

    // tree ฝั่งปกติ
    public function childrenRecursiveActive()
    {
        return $this->childrenActive()
                    ->with(['childrenRecursiveActive', 'filesActive']);
    }

    // tree ฝั่งถังขยะ
    public function childrenRecursiveTrashed()
    {
        return $this->childrenTrashed()
                    ->with(['childrenRecursiveTrashed', 'filesTrashed']);
    }


    public function trashedChildren()
    {
        return $this->hasMany(Folders::class, 'parent_id')
                    ->where('is_trashed', 1);
    }

    public function trashedChildrenRecursive()
    {
        return $this->trashedChildren()
                    ->with('trashedChildrenRecursive');
    }

    public function openedByUser()
{
    return $this->belongsTo(User::class, 'last_opened_by');
}


public function starredFiles()
{
    return $this->hasMany(Files::class, 'folder_id')
        ->where('is_starred', 1)
        ->where('is_trashed', 0);
}

}



