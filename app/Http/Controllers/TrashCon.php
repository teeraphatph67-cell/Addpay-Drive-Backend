<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Response;
use App\Models\Files;
use App\Models\Folders;
use Illuminate\Support\Facades\File;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Trash;
use Illuminate\Support\Facades\DB;
use App\Models\Permissions;

use Illuminate\Support\Facades\Hash;
class TrashCon extends Controller
{
    protected $response;

    public function __construct()
    {
        $this->response = new Response();
    }

public function emptyTrash()
{
    $user = JWTAuth::parseToken()->authenticate();

    DB::transaction(function () use ($user) {

        // 1) ลบโฟลเดอร์ในถังขยะ
        $trashedFolders = Folders::where('owner_id', $user->id)
            ->where('is_trashed', 1)
            ->get();

        foreach ($trashedFolders as $folder) {

            $this->deleteFolderRecursive($folder);

            // 🔥 ลบ permissions ของโฟลเดอร์
            Permissions::where('target_type', 'folder')
                ->where('target_id', $folder->id)
                ->delete();

            $folder->delete();
        }

        // 2) ลบไฟล์เดี่ยวในถังขยะ
        $trashedFiles = Files::where('owner_id', $user->id)
            ->where('is_trashed', 1)
            ->get();

        foreach ($trashedFiles as $file) {

            $fullPath = base_path('public/' . $file->file_path);

            if (File::exists($fullPath)) {
                File::delete($fullPath);
            }

            // 🔥 ลบ permissions ของไฟล์
            Permissions::where('target_type', 'file')
                ->where('target_id', $file->id)
                ->delete();

            $file->delete();
        }
    });

    return response()->json([
        'success' => true,
        'message' => 'ลบถาวรทั้งหมดในถังขยะเรียบร้อยแล้ว',
    ]);
}



    public function bulkDestroy(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $fileIds   = $request->input('file_ids', []);
        $folderIds = $request->input('folder_ids', []);

        // กันเคสไม่มีอะไรส่งมา
        if (empty($fileIds) && empty($folderIds)) {
            return response()->json([
                'success' => false,
                'message' => 'กรุณาเลือกรายการที่จะลบ',
            ], 400);
        }

        // 1) จัดการโฟลเดอร์ที่เลือกก่อน
        if (!empty($folderIds)) {
            $folders = Folders::whereIn('id', $folderIds)
                ->where('owner_id', $user->id)
                ->where('is_trashed', 1) // ลบเฉพาะที่อยู่ในถังขยะจริง ๆ
                ->get();

            foreach ($folders as $folder) {
                $this->deleteFolderRecursive($folder);
                $folder->delete();
            }
        }

        // 2) จัดการไฟล์ที่เลือก
        if (!empty($fileIds)) {
            $files = Files::whereIn('id', $fileIds)
                ->where('owner_id', $user->id)
                ->where('is_trashed', 1)
                ->get();

            foreach ($files as $file) {
                $fullPath = base_path('public/' . $file->file_path);

                if (File::exists($fullPath)) {
                    File::delete($fullPath);
                }

                $file->delete();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'ลบรายการที่เลือกแบบถาวรเรียบร้อยแล้ว',
        ]);
    }

    // ✅ helper ลบโฟลเดอร์ + ลูก + ไฟล์ในนั้น แบบ recursive
    // (เอา logic จาก FolderCon ของนายมาใช้ได้เลย)
    private function deleteFolderRecursive(Folders $folder)
    {
        $folder->load(['ManyFile', 'children']);

        // 1) ลบไฟล์ในโฟลเดอร์นี้
        foreach ($folder->ManyFile as $file) {
            $fullPath = base_path('public/' . $file->file_path);

            if (File::exists($fullPath)) {
                File::delete($fullPath);
            }

            $file->delete();
        }

        // 2) ลบโฟลเดอร์ลูกทั้งหมด
        foreach ($folder->children as $childFolder) {
            $this->deleteFolderRecursive($childFolder);
            $childFolder->delete();
        }

        // 3) ลบ directory จริงของโฟลเดอร์นี้
        $folderPath = base_path('public/' . $folder->url_file);

        if (File::isDirectory($folderPath)) {
            File::deleteDirectory($folderPath);
        }
    }


        public function bulkMoveToTrash(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $fileIds   = $request->input('file_ids', []);
        $folderIds = $request->input('folder_ids', []);

        if (empty($fileIds) && empty($folderIds)) {
            return response()->json([
                'success' => false,
                'message' => 'กรุณาเลือกรายการที่จะย้ายไปถังขยะ',
            ], 400);
        }

        // 1) โฟลเดอร์ที่เลือก → ย้ายทั้งโฟลเดอร์ + ลูก + ไฟล์ในนั้น ลงถังขยะ
        if (!empty($folderIds)) {
            $folders = Folders::whereIn('id', $folderIds)
                ->where('owner_id', $user->id)
                ->where('is_trashed', 0)   // กันย้ายซ้ำ
                ->get();

            foreach ($folders as $folder) {
                $this->trashFolderRecursive($folder);
            }
        }

        // 2) ไฟล์เดี่ยวที่เลือก → mark is_trashed = 1
        if (!empty($fileIds)) {
            $files = Files::whereIn('id', $fileIds)
                ->where('owner_id', $user->id)
                ->where('is_trashed', 0)
                ->get();

            foreach ($files as $file) {
                $file->is_trashed = 1;
                $file->save();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'ย้ายรายการที่เลือกไปถังขยะเรียบร้อยแล้ว',
        ]);
    }

    // ✅ helper: ย้ายโฟลเดอร์ + ไฟล์ + โฟลเดอร์ลูก ไปถังขยะแบบ recursive
    private function trashFolderRecursive(Folders $folder)
    {
        $folder->load(['ManyFile', 'children']);

        // ย้ายไฟล์ในโฟลเดอร์นี้ลงถังขยะ
        foreach ($folder->ManyFile as $file) {
            if (!$file->is_trashed) {
                $file->is_trashed = 1;
                $file->save();
            }
        }

        // ย้ายโฟลเดอร์ลูกทั้งหมดลงถังขยะ
        foreach ($folder->children as $child) {
            $this->trashFolderRecursive($child);
        }

        // mark โฟลเดอร์ตัวเอง
        if (!$folder->is_trashed) {
            $folder->is_trashed = 1;
            $folder->save();
        }
    }


}